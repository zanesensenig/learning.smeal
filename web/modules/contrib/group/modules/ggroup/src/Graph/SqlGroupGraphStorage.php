<?php

namespace Drupal\ggroup\Graph;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Cache\Cache;

/**
 * SQL based storage of the group relationship graph.
 */
class SqlGroupGraphStorage implements GroupGraphStorageInterface {

  /**
   * Static cache for ancestor lookup.
   *
   * This array allow us to retrieve the ancestors faster.
   *
   * @var int[][]
   *   An nested array containing all ancestor group IDs for a group.
   */
  protected $ancestors = [];

  /**
   * Static cache for descendant lookup.
   *
   * This array allow us to retrieve the ancestors faster.
   *
   * @var int[][]
   *   An nested array containing all ancestor group IDs for a group.
   */
  protected $descendants = [];

  /**
   * Static cache for direct ancestor lookup.
   *
   * This array allow us to retrieve the ancestors faster.
   *
   * @var int[][]
   *   An nested array containing all ancestor group IDs for a group.
   */
  protected $directAncestors = [];

  /**
   * Static cache for direct descendant lookup.
   *
   * This array allow us to retrieve the ancestors faster.
   *
   * @var int[][]
   *   An nested array containing all ancestor group IDs for a group.
   */
  protected $directDescendants = [];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  protected $loaded = [];

  /**
   * Contracts a new class instance.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Invalidate the cache tags for the groups provided.
   */
  protected function invalidate($gids) {
    if (!empty($gids)) {
      // Create a cache tag for all the groups passed in.
      foreach ($gids as $gid) {
        $cache_tags[] = 'group:' . $gid;

        // @TODO: Should the cache be actively rebuilt instead?
        // Indicate that this group needs to be reloaded.
        $this->loaded[$gid] = FALSE;

        // Remove all the values for this group since the values are no longer
        // considered valid.
        unset($this->ancestors[$gid]);
        unset($this->descendants[$gid]);
        unset($this->directAncestors[$gid]);
        unset($this->directDescendants[$gid]);
      }

      // And invalidate the tags.
      if (!empty($cache_tags)) {
        Cache::invalidateTags($cache_tags);
      }
    }
  }

  /**
   * Fetch the records from graph for the provided group and cache them.
   *
   * This is mostly done for performance reasons. When having lots of groups,
   * getting/checking the ancestors or descendants in separate queries is a lot
   * slower.
   */
  protected function loadGroupMapping($gid) {
    $cid = 'ggroup_graph_map:' . $gid;
    if ($cache = \Drupal::cache()->get($cid)) {
      $mapping = $cache->data;
    }
    else {
      $query = $this->connection->select('group_graph', 'gg')
        ->fields('gg', ['start_vertex', 'end_vertex']);

      // This Or Group is identical to the one used in the query below.
      $or_group = $query->orConditionGroup();
      $or_group->condition('gg.start_vertex', $gid)
        ->condition('gg.end_vertex', $gid);

      // Add the Or Group
      $query->condition($or_group);

      $mapping['descendants'] = $query->execute()->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);
      $mapping['ancestors'] = $query->execute()->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP, 1);

      // Now load direct relatives.
      $query = $this->connection->select('group_graph', 'gg')
        ->fields('gg', ['start_vertex', 'end_vertex']);

      // Add conditions to restrict this to direct descendants for this group.
      $query->condition('hops', 0)
        ->condition($or_group);

      $mapping['directDescendants'] = $query->execute()->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);
      $mapping['directAncestors'] = $query->execute()->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP, 1);

      // Descendants and Ancestors contain direct relatives as well.
      // Combine ancestors and descendants to get a full list of all of the
      // groups returned to tag this cache with.
      $groups = array_merge($mapping['descendants'], $mapping['ancestors']);
      $groups[] = $gid;

      // Build the cache tags for all of the (deduped) groups.
      $groups = $this->flattenGroups($groups);
      $groups = array_unique($groups, SORT_NUMERIC);
      $cache_tags = [];
      foreach($groups as $group_id) {
        $cache_tags[] = 'group:' . $group_id;
      }

      \Drupal::cache()->set($cid, $mapping, Cache::PERMANENT, $cache_tags);
    }

    // Merge relatives with those already set.
    $this->mergeMappings($mapping);

    // Indicate that this group ID has been loaded.
    $this->loaded[$gid] = TRUE;
  }

  /**
   * Flattens an array of groups into a simple, single level array.
   *
   * @param array $groups
   *  The groups that need to be flattened.
   *
   * @return array $flat_groups
   */
  private function flattenGroups($groups) {
    $flat_groups = [];
    if (!empty($groups)) {
      foreach ($groups as $group_val) {
        if (is_array($group_val)) {
          $flat_groups += $group_val;
        }
        else {
          $flat_groups[] = $group_val;
        }
      }
    }
    return $flat_groups;
  }

  /**
   * Merges a multi-dimentional mapping array with the existing mapping values.
   *
   * @param array $mapping
   *  A multi-dimentional array of mappings keyed by the mapping relation.
   *
   * @return $this
   */
  private function mergeMappings($mapping) {
    if (!empty($mapping)) {
      foreach($mapping as $relation => $relatives) {
        if (!empty($relatives)) {
          $rootRelation = &$this->{$relation} ?: [];
          foreach($relatives as $parentGid => $groupMap) {

            $groupMap = array_combine($groupMap, $groupMap);
            if (!empty($rootRelation[$parentGid])) {
              $rootRelation[$parentGid] = $rootRelation[$parentGid] + $groupMap;
            }
            else {
              $rootRelation[$parentGid] = $groupMap;
            }
          }
        }
      }
    }
    return $this;
  }

  /**
   * Gets the edge ID relating the parent group to the child group.
   *
   * @param int $parent_group_id
   *   The ID of the parent group.
   * @param int $child_group_id
   *   The ID of the child group.
   *
   * @return int
   *   The ID of the edge relating the parent group to the child group.
   */
  protected function getEdgeId($parent_group_id, $child_group_id) {
    $query = $this->connection->select('group_graph', 'gg')
      ->fields('gg', ['id']);
    $query->condition('start_vertex', $parent_group_id);
    $query->condition('end_vertex', $child_group_id);
    $query->condition('hops', 0);
    return $query->execute()->fetchField();
  }

  /**
   * Relates the parent group to the child group.
   *
   * This method only creates the relationship from the parent group to the
   * child group and not any of the inferred relationships based on what other
   * relationships the parent group and the child group already have.
   *
   * @param int $parent_group_id
   *   The ID of the parent group.
   * @param int $child_group_id
   *   The ID of the child group.
   *
   * @return int
   *   The ID of the new edge relating the parent group to the child group.
   */
  protected function insertEdge($parent_group_id, $child_group_id) {
    $new_edge_id = $this->connection->insert('group_graph')
      ->fields([
        'start_vertex' => $parent_group_id,
        'end_vertex' => $child_group_id,
        'hops' => 0,
      ])
      ->execute();

    $this->connection->update('group_graph')
      ->fields([
        'entry_edge_id' => $new_edge_id,
        'exit_edge_id' => $new_edge_id,
        'direct_edge_id' => $new_edge_id,
      ])
      ->condition('id', $new_edge_id)
      ->execute();

    return $new_edge_id;
  }

  /**
   * Insert parent group incoming edges to child group.
   *
   * @param int $edge_id
   *   The existing edge ID relating the parent group to the child group.
   * @param int $parent_group_id
   *   The ID of the parent group.
   * @param int $child_group_id
   *   The ID of the child group.
   */
  protected function insertEdgesParentIncomingToChild($edge_id, $parent_group_id, $child_group_id) {
    // Since fields are added before expressions, all fields are added as
    // expressions to keep the field order intact.
    $query = $this->connection->select('group_graph', 'gg');
    $query->addExpression('gg.id', 'entry_edge_id');
    $query->addExpression($edge_id, 'direct_edge_id');
    $query->addExpression($edge_id, 'exit_edge_id');
    $query->addExpression('gg.start_vertex', 'start_vertex');
    $query->addExpression($child_group_id, 'end_vertex');
    $query->addExpression('gg.hops + 1', 'hops');
    $query->condition('end_vertex', $parent_group_id);

    $this->connection->insert('group_graph')
      ->fields([
        'entry_edge_id',
        'direct_edge_id',
        'exit_edge_id',
        'start_vertex',
        'end_vertex',
        'hops',
      ])
      ->from($query)
      ->execute();
  }

  /**
   * Insert parent group outgoing edges to child group.
   *
   * @param int $edge_id
   *   The existing edge ID relating the parent group to the child group.
   * @param int $parent_group_id
   *   The ID of the parent group.
   * @param int $child_group_id
   *   The ID of the child group.
   */
  protected function insertEdgesParentToChildOutgoing($edge_id, $parent_group_id, $child_group_id) {
    // Since fields are added before expressions, all fields are added as
    // expressions to keep the field order intact.
    $query = $this->connection->select('group_graph', 'gg');
    $query->addExpression($edge_id, 'entry_edge_id');
    $query->addExpression($edge_id, 'direct_edge_id');
    $query->addExpression('gg.id', 'exit_edge_id');
    $query->addExpression($parent_group_id, 'start_vertex');
    $query->addExpression('gg.end_vertex', 'end_vertex');
    $query->addExpression('gg.hops + 1', 'hops');
    $query->condition('start_vertex', $child_group_id);

    $this->connection->insert('group_graph')
      ->fields([
        'entry_edge_id',
        'direct_edge_id',
        'exit_edge_id',
        'start_vertex',
        'end_vertex',
        'hops',
      ])
      ->from($query)
      ->execute();
  }

  /**
   * Insert the parent group incoming edges to the child group outgoing edges.
   *
   * @param int $edge_id
   *   The existing edge ID relating the parent group to the child group.
   * @param int $parent_group_id
   *   The ID of the parent group.
   * @param int $child_group_id
   *   The ID of the child group.
   */
  protected function insertEdgesParentIncomingToChildOutgoing($edge_id, $parent_group_id, $child_group_id) {
    // Since fields are added before expressions, all fields are added as
    // expressions to keep the field order intact.
    $query = $this->connection->select('group_graph', 'parent_gg');
    $query->join('group_graph', 'child_gg');
    $query->addExpression('parent_gg.id', 'entry_edge_id');
    $query->addExpression($edge_id, 'direct_edge_id');
    $query->addExpression('child_gg.id', 'exit_edge_id');
    $query->addExpression('parent_gg.start_vertex', 'start_vertex');
    $query->addExpression('child_gg.end_vertex', 'end_vertex');
    $query->addExpression('parent_gg.hops + child_gg.hops + 1', 'hops');
    $query->condition('parent_gg.end_vertex', $parent_group_id);
    $query->condition('child_gg.start_vertex', $child_group_id);

    $this->connection->insert('group_graph')
      ->fields([
        'entry_edge_id',
        'direct_edge_id',
        'exit_edge_id',
        'start_vertex',
        'end_vertex',
        'hops',
      ])
      ->from($query)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getGraph($group_id) {
    $query = $this->connection->select('group_graph', 'gg')
      ->fields('gg', ['start_vertex', 'end_vertex'])
      ->orderBy('hops')
      ->orderBy('start_vertex');

     // This Or Group is identical to the one used in the query below.
    $or_group = $query->orConditionGroup();
    $or_group->condition('gg.start_vertex', $group_id)
      ->condition('gg.end_vertex', $group_id);

    // Add the Or Group
    $query->condition($or_group);
    return $query->execute()->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function addEdge($parent_group_id, $child_group_id) {
    if ($parent_group_id === $child_group_id) {
      return FALSE;
    }

    $parent_child_edge_id = $this->getEdgeId($parent_group_id, $child_group_id);

    if (!empty($parent_child_edge_id)) {
      return $parent_child_edge_id;
    }

    $child_parent_edge_id = $this->getEdgeId($parent_group_id, $child_group_id);

    if (!empty($child_parent_edge_id)) {
      return $child_parent_edge_id;
    }

    if ($this->isDescendant($parent_group_id, $child_group_id)) {
      throw new CyclicGraphException($parent_group_id, $child_group_id);
    }

    $new_edge_id = $this->insertEdge($parent_group_id, $child_group_id);
    $this->insertEdgesParentIncomingToChild($new_edge_id, $parent_group_id, $child_group_id);
    $this->insertEdgesParentToChildOutgoing($new_edge_id, $parent_group_id, $child_group_id);
    $this->insertEdgesParentIncomingToChildOutgoing($new_edge_id, $parent_group_id, $child_group_id);

    $this->invalidate([$parent_group_id, $child_group_id]);

    return $new_edge_id;
  }

  /**
   * {@inheritdoc}
   */
  public function removeEdge($parent_group_id, $child_group_id) {
    $edge_id = $this->getEdgeId($parent_group_id, $child_group_id);

    if (empty($edge_id)) {
      return;
    }

    $edges_to_delete = [];

    $query = $this->connection->select('group_graph', 'gg')
      ->fields('gg', ['id']);
    $query->condition('direct_edge_id', $edge_id);
    $results = $query->execute();

    while ($id = $results->fetchField()) {
      $edges_to_delete[] = $id;
    }

    if (empty($edges_to_delete)) {
      return;
    }

    do {
      $total_edges = count($edges_to_delete);

      $query = $this->connection->select('group_graph', 'gg')
        ->fields('gg', ['id']);
      $query->condition('hops', 0);
      $query->condition('id', $edges_to_delete, 'NOT IN');
      $query_or_conditions = new Condition('OR');
      $query_or_conditions->condition('entry_edge_id', $edges_to_delete, 'IN');
      $query_or_conditions->condition('exit_edge_id', $edges_to_delete, 'IN');
      $query->condition($query_or_conditions);
      $results = $query->execute();

      while ($id = $results->fetchField()) {
        $edges_to_delete[] = $id;
      }
    } while (count($edges_to_delete) > $total_edges);

    $this->connection->delete('group_graph')
      ->condition('id', $edges_to_delete, 'IN')
      ->execute();

    $this->invalidate([$parent_group_id, $child_group_id]);
  }

  /**
   * Load the ancestry mapping for a group if it isn't loaded already.
   */
  private function loadMap($gid) {
    if (empty($this->loaded[$gid])) {
      $this->loadGroupMapping($gid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectDescendants($group_id) {
    $this->loadMap($group_id);
    return isset($this->directDescendants[$group_id]) ? $this->directDescendants[$group_id] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectAncestors($group_id) {
    $this->loadMap($group_id);
    return isset($this->directAncestors[$group_id]) ? $this->directAncestors[$group_id] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescendants($group_id) {
    $this->loadMap($group_id);
    return isset($this->descendants[$group_id]) ? $this->descendants[$group_id] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getAncestors($group_id) {
    $this->loadMap($group_id);
    return isset($this->ancestors[$group_id]) ? $this->ancestors[$group_id] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function isDirectDescendant($a, $b) {
    $this->loadMap($b);
    return isset($this->directDescendants[$b]) ? in_array($a, $this->directDescendants[$b]) : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isDirectAncestor($a, $b) {
    $this->loadMap($b);
    return isset($this->directAncestors[$b]) ? in_array($a, $this->directAncestors[$b]) : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isDescendant($a, $b) {
    $this->loadMap($b);
    return isset($this->descendants[$b]) ? in_array($a, $this->descendants[$b]) : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAncestor($a, $b) {
    $this->loadMap($b);
    return isset($this->ancestors[$b]) ? in_array($a, $this->ancestors[$b]) : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPath($parent_group_id, $child_group_id) {

    if (!$this->isAncestor($parent_group_id, $child_group_id)) {
      return [];
    }
    $visited = [];
    $solutions = [];

    // Enqueue the origin vertex and mark as visited.
    $queue = new \SplQueue();
    $queue->enqueue($child_group_id);
    $visited[$child_group_id] = TRUE;

    // This is used to track the path back from each node.
    $paths = [];
    $paths[$child_group_id][] = $child_group_id;

    // While queue is not empty and destination not found.
    while (!$queue->isEmpty() && $queue->bottom() != $parent_group_id) {
      $child_id = $queue->dequeue();

      // Make sure the mapping info for the child is loaded.
      $this->loadMap($child_id);

      // Get parents for child in queue.
      if (isset($this->directAncestors[$child_id])) {
        $parent_ids = $this->directAncestors[$child_id];

        foreach ($parent_ids as $parent_id) {
          if ((int) $parent_id === (int) $parent_group_id) {
            // Add this path to the list of solutions.
            $solution = $paths[$child_id];
            $solution[] = $parent_id;
            $solutions[] = $solution;
          }
          else {
            if (!isset($visited[$parent_id])) {
              // If not yet visited, enqueue parent id and mark as visited.
              $queue->enqueue($parent_id);
              $visited[$parent_id] = TRUE;
              // Add parent to current path.
              $paths[$parent_id] = $paths[$child_id];
              $paths[$parent_id][] = $parent_id;
            }
          }
        }
      }
    }

    return $solutions;
  }

}
