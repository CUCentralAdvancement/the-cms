<?php

namespace Drupal\cua_ir20_content_types\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns responses for CUA IR20 Content Types routes.
 */
class CuaIr20ContentTypesController extends ControllerBase {

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $node_service;

  /**
   * @var mixed
   */
  private $serializer;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $file_service;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $taxonomy_service;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $paragraph_service;

  public function __construct() {
    $this->serializer = \Drupal::service('serializer');
    $this->node_service = $this->entityTypeManager()->getStorage('node');
    $this->file_service = $this->entityTypeManager()->getStorage('file');
    $this->taxonomy_service = $this->entityTypeManager()->getStorage('taxonomy_term');
    $this->paragraph_service = $this->entityTypeManager()->getStorage('paragraph');
  }

  /**
   * Builds the response.
   *
   * @param string $slug
   *   Short human-readable name of story.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Story data in JSON format.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function fund(string $slug): JsonResponse {
    $nids = $this->node_service->loadByProperties(['field_slug' => "fund/$slug"]);
    $data = $this->node_service->load(array_keys($nids)[0]);
    $node = json_decode($this->serializer->serialize($data, 'json', ['plugin_id' => 'entity']), TRUE);

    // Direct fields.
    $fund = [];
    $fund['title'] = $node["title"][0]["value"];
    $fund['description'] = $node["body"][0]["value"];
    $fund['campus'] = $node["field_campus"][0]["value"];
    $fund['slug'] = $node["field_slug"][0]["value"];
    $fund['interest'] = $node["field_interest"][0]["value"];
    $fund['allocation_code'] = $node["field_allocation_code"][0]["value"];
    $fund['suggested_amount'] = $node["field_suggested_amount"][0]["value"];
    $fund['marketing_content'] = $node["field_marketing_content"][0]["value"];
    $fund['created_at'] = $node["created"][0]["value"];
    $fund['updated_at'] = $node["changed"][0]["value"];
    $fund['fund_type'] = $node["field_fund_type"][0]["value"];

    // Keywords.
    $keywords = [];
    if (isset($node["field_keywords"])) {
      foreach ($node["field_keywords"] as $keyword_data) {
        $term = $this->taxonomy_service->load($keyword_data["target_id"]);
        $term_data = json_decode($this->serializer->serialize($term, 'json', ['plugin_id' => 'taxonomy']), TRUE);
        $keywords[] = $term_data["name"][0]["value"];
      }
    }
    $fund['keywords'] = implode(',', $keywords);

    return new JsonResponse($fund);
  }

  /**
   * Dd.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   List of stories as data in JSON format.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function funds(): JsonResponse {
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'fund')
      ->execute();
    $nodes = $this->node_service->loadMultiple($nids);
    $data = json_decode($this->serializer->serialize($nodes, 'json', ['plugin_id' => 'entity']), TRUE);

    return new JsonResponse($data);
  }

  /**
   * Dd.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Lists of story slugs returned in an array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function paths(string $type): JsonResponse {
    $serializer = \Drupal::service('serializer');
    $node_service = $this->entityTypeManager()->getStorage('node');

    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', $type)
      ->execute();
    $nodes = $node_service->loadMultiple($nids);
    $data = json_decode($serializer->serialize($nodes, 'json', ['plugin_id' => 'entity']), TRUE);

    $paths = [];
    foreach ($data as $node) {
      $paths[] = explode('/', $node["field_slug"][0]["value"])[1];
    }

    return new JsonResponse($paths);
  }

  /**
   * Triggers CI test run via GitHub Actions.
   *
   * @param string $name
   *   Name of the review app.
   * @param string $branch
   *   Branch used to trigger CI run.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Whether success or not.
   */
  public function reviewApps(string $name, string $branch): JsonResponse {
    $data = json_encode([
      'event_type' => "heroku-ci",
      'client_payload' => [
        'app_url' => "https://$name.herokuapp.com",
        'branch' => "feature/$branch",
      ],
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/CUCentralAdvancement/digital-dash-cu-adv/dispatches');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Token ' . getenv('YOUR_GITHUB_TOKEN'),
      'User-agent: Drupal',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $result = curl_exec($ch);

    return new JsonResponse($result, 200);
  }

  /**
   * Builds the response.
   *
   * @param string $id
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Story data in JSON format.
   */
  public function impacts(string $id): JsonResponse {
    $node = $this->getNode($id);
    $result = [
      'title' => $node["title"][0]["value"],
      'body' => $node["body"][0]["value"],
      'layout' => $this->getLayout($this->getParagraphs($node["field_content_stuff"])),
    ];
    return new JsonResponse($result);
  }


  private function getTextBlockContent($block): array {
    return [
      'content' => $block[0]["value"],
      'type' => 'text_block',
    ];
  }

  private function getImageContent($image): array {
    $file = $this->file_service->load($image[0]["target_id"]);
    $file_stuff = json_decode($this->serializer->serialize($file, 'json', ['plugin_id' => 'file']), TRUE);
    return [
      'alt' => $image[0]["alt"],
      'caption' => $image[0]["title"],
      'width' => $image[0]["width"],
      'height' => $image[0]["height"],
      'url' => $file_stuff["uri"][0]["url"],
      'type' => 'image',
    ];
  }

  private function setSections(int &$section_number, string &$layout_type, array &$layout, array $par) {
    $section_number++;
    switch ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["layout"]) {
      case 'layout_onecol':
        $layout_type = 'layout_onecol';
        $layout['sections']["layout_onecol_$section_number"] = ['content' => []];
        break;
      case 'layout_twocol_section':
        $layout_type = 'layout_twocol_section';
        $layout['sections']["layout_twocol_section_$section_number"] = ['first' => [], 'second' => []];
        break;
      case 'layout_threecol_section':
        $layout_type = 'layout_threecol_section';
        $layout['sections']["layout_threecol_section_$section_number"] = [
          'first' => [],
          'second' => [],
          'third' => [],
        ];
        break;
      case 'layout_fourcol_section':
        $layout_type = 'layout_fourcol_section';
        $layout['sections']["layout_fourcol_section_$section_number"] = [
          'first' => [],
          'second' => [],
          'third' => [],
          'fourth' => [],
        ];
        break;
    }
  }

  private function getLayout(array $paragraphs): array {
    $layout = [];
    $section_number = 0;
    $layout_type = '';
    foreach ($paragraphs as $par) {
      if ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["layout"] !== '') {
        $this->setSections($section_number, $layout_type, $layout, $par);
      }

      if ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["region"] !== '') {
        $content = [];
        switch ($par["type"][0]["target_id"]) {
          case 'text_block':
            $content = $this->getTextBlockContent($par["field_content"]);
            break;
          case 'image':
            $content = $this->getImageContent($par["field_image"]);
            break;
        }
        $content['styles'] = $this->getStyles($par["field_styles"]);
        $layout['sections'][$layout_type . '_' . $section_number][$par["behavior_settings"][0]["value"]["layout_paragraphs"]["region"]][] = $content;
      }
    }
    return $layout;
  }

  private function getParagraphs($field_content_stuff): array {
    return array_map(function ($rs) {
      return json_decode($this->serializer->serialize($this->paragraph_service->load($rs['target_id']), 'json',
        ['plugin_id' => 'paragraph']), TRUE);
    }, $field_content_stuff);
  }

  private function getStyles($field_styles): array {
    return array_map(function ($style) {
      return $style['value'];
    }, $field_styles);
  }

  private function getNode($nid) {
    $nids = $this->node_service->loadByProperties(['nid' => $nid]);
    $data = $this->node_service->load(array_keys($nids)[0]);
    return json_decode($this->serializer->serialize($data, 'json', ['plugin_id' => 'entity']), TRUE);
  }

}
