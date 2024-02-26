<?php
/**
 * Plugin Name:       Figma Content
 * Plugin URI:        vndrvn.com/wp-figma
 * Description:       Display Figma content in posts
 * Requires at least: 6.1
 * Requires PHP:      7.0
 * Version:           0.0.1
 * Author:            Andrew VanderVeen
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-figma
 *
 * @package           vndrvn
 */

require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

function vndrvn_wp_figma_block_init() {
	register_block_type(__DIR__ . '/build');
}

add_action('init', 'vndrvn_wp_figma_block_init');

class FigmaException extends Exception {
  public function __construct(string $message) {
    parent::__construct($message);
  }
}

class FigmaClient {
  private const BASE_URL = 'https://api.figma.com/v1/';

  private string $pat;

  public function __construct(string $pat) {
    $this->pat = $pat;
  }

  public function getProjects(string $team) {
    return $this->get('teams/' . $team . '/projects');
  }

  public function getFiles(string $project) {
    return $this->get('projects/' . $project . '/files');
  }

  public function getFile(string $file) {
    return $this->get('files/' . $file);
  }

  public function getImages(string $file, array $ids, string $format) {
    return $this->get('images/' . $file . '?format=' . $format . '&ids=' . implode(',', $ids));
  }

  private function get(string $path) {
    $response = wp_remote_get(
      self::BASE_URL . $path,
      array('headers' => array('X-FIGMA-TOKEN' => $this->pat))
    );

    if (wp_remote_retrieve_response_code($response) != 200) {
      throw new FigmaException('Failed request for ' . $path);
    }

    return json_decode(wp_remote_retrieve_body($response));
  }
}

$figmaClient = new FigmaClient('FIGMA-TOKEN');

function getFigmaTeam($data) {
  global $figmaClient;
  $team = $figmaClient->getProjects($data['id']);
  return array(
    'name' => $team->name,
    'projects' => array_map(
      fn($project): array => array(
        'id' => $project->id,
        'name' => $project->name,
        'files' => array_map(
          fn($file): array => array(
            'id' => $file->key,
            'name' => $file->name,
            'thumbnailUrl' => $file->thumbnail_url
          ),
          $figmaClient->getFiles($project->id)->files
        )
      ),
      $team->projects
    )
  );
}

function getFrames($nodes, &$result) {
  foreach ($nodes as $node) {
    if ($node->type == 'FRAME') {
      array_push($result, array(
        'id' => $node->id,
        'name' => $node->name
      ));
    }

    if (property_exists($node, 'children')) {
      getFrames($node->children, $result);
    }
  }
}

function getFigmaImages($data) {
  global $figmaClient;
  $file = $figmaClient->getFile($data['id']);
  $nodes = array();
  getFrames($file->document->children, $nodes);

  if (array_key_exists('nodeIds', (array) $data)) {
    $nodeIds = $data['nodeIds'];
  } else {
    $nodeIds = array_map(fn($node): string => $node['id'], $nodes);
  }

  $images = $figmaClient->getImages($data['id'], $nodeIds, $data['format']);
  return array(
    'fileName' => $file->name,
    'frames' => array_map(
      fn($node): array => array(
        'id' => $node['id'],
        'name' => $node['name'],
        'image' => ((array) $images->images)[$node['id']]
      ),
      $nodes
    )
  );
}

// TODO replace media_sideload_image call with solution from this SO answer:
// https://wordpress.stackexchange.com/a/44115
function attachFigmaImage($data) {
  $file = getFigmaImages(array(
    'id' => $data['fileId'],
    'nodeIds' => array($data['nodeId']),
    'format' => $data['format']
  ));

  $fileName = $file['name'];
  $frame = $file['frames'][0];
  return array(
    'attachmentId' => media_sideload_image(
      $frame['image'],
      $data['postId'],
      'Figma image (' .
        'file: ' . $fileName . ', ' .
        'frame: ' . $frame['name'] . ', ' .
        'format: ' . $data['format'] . ')',
      'id'
    )
  );
}

add_action('rest_api_init', function() {
  register_rest_route('wp-figma/v1', 'teams/(?P<id>\d+)', array(
    'methods' => 'GET',
    'callback' => 'getFigmaTeam',
    'args' => array(
      'id' => array(
        'validate_callback' => function($param, $request, $key) {
          return is_numeric($param);
        }
      )
    ),
    'permission_callback' => function() {
      return true;
      //return current_user_can('edit_others_posts');
    }
  ));

  register_rest_route('wp-figma/v1', 'files/(?P<id>.+)/images', array(
    'methods' => 'GET',
    'callback' => 'getFigmaImages',
    'args' => array(
      'id' => array(
        'validate_callback' => function($param, $request, $key) {
          return $param != '';
        }
      ),
      'format' => array(
        'default' => 'svg',
        'validate_callback' => function($param, $request, $key) {
          return in_array($param, array('jpg', 'png', 'svg', 'pdf'));
        }
      )
    ),
    'permission_callback' => function() {
      return true;
      //return current_user_can('edit_others_posts');
    }
  ));

  register_rest_route('wp-figma/v1', 'files/(?P<fileId>.+)/images/(?P<nodeId>.+)/posts/(?P<postId>.+)', array(
    'methods' => 'POST',
    'callback' => 'attachFigmaImage',
    'args' => array(
      'fileId' => array(
        'validate_callback' => function($param, $request, $key) {
          return $param != '';
        }
      ),
      'nodeId' => array(
        'validate_callback' => function($param, $request, $key) {
          return $param != '';
        }
      ),
      'postId' => array(
        'validate_callback' => function($param, $request, $key) {
          return $param != '';
        }
      ),
      'format' => array(
        'default' => 'svg',
        'validate_callback' => function($param, $request, $key) {
          return in_array($param, array('jpg', 'png', 'svg', 'pdf'));
        }
      )
    ),
    'permission_callback' => function() {
      return true;
      //return current_user_can('edit_others_posts');
    }
  ));
});
