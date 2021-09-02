<?php

namespace Drupal\cua_ir21_content_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns responses for CUA IR20 Content Types routes.
 */
class CuaIr21ContentApiController extends ControllerBase {

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
    $this->taxonomy_service = $this->entityTypeManager()
      ->getStorage('taxonomy_term');
    $this->paragraph_service = $this->entityTypeManager()
      ->getStorage('paragraph');
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
    curl_setopt($ch, CURLOPT_URL,
      'https://api.github.com/repos/CUCentralAdvancement/digital-dash-cu-adv/dispatches');
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
   * @param string $slug
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Story data in JSON format.
   */
  public function impacts(string $slug): JsonResponse {
    $node = $this->getNode($slug);

    // Image Main.
    $file = $this->file_service->load($node["field_image_main"][0]["target_id"]);
    $file_stuff = json_decode($this->serializer->serialize($file, 'json',
      ['plugin_id' => 'file']), TRUE);
    $main_image = [
      'alt' => $node["field_image_main"][0]["alt"],
      'caption' => $node["field_image_main"][0]["title"],
      'width' => $node["field_image_main"][0]["width"],
      'height' => $node["field_image_main"][0]["height"],
      'url' => $file_stuff["uri"][0]["url"],
    ];

    $result = [
      'title' => $node["title"][0]["value"],
      'body' => $node["body"][0]["value"],
      'main_image' => $main_image,
      //      'layout' => $this->getLayout($this->getParagraphs($node["field_content_stuff"])),
      'layout' => $this->getOneColumnLayout($this->getParagraphs($node["field_content_stuff"])),
    ];
    return new JsonResponse($result);
  }


  private function getTextBlockContent($block): array {
    return [
      'content' => $block[0]["value"],
      'id' => $this->getUidFromString($block[0]["value"]),
      'type' => 'text_block',
    ];
  }

  private function getImageContent($image): array {
    $file = $this->file_service->load($image[0]["target_id"]);
    $file_stuff = json_decode($this->serializer->serialize($file, 'json',
      ['plugin_id' => 'file']), TRUE);
    return [
      'alt' => $image[0]["alt"],
      'caption' => $image[0]["title"],
      'width' => $image[0]["width"],
      'height' => $image[0]["height"],
      'url' => $file_stuff["uri"][0]["url"],
      'id' => $this->getUidFromString($file_stuff["uri"][0]["url"]),
      'type' => 'image',
    ];
  }

  private function setSections(
    int &$section_number,
    string &$layout_type,
    array &$layout,
    array $par
  ) {
    $section_number++;
    $widths = $par["behavior_settings"][0]["value"]["layout_paragraphs"]["config"]["column_widths"];
    switch ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["layout"]) {
      case 'layout_onecol':
        $layout_type = 'layout_onecol';
        $layout['sections']["layout_onecol_$section_number"] = [
          'content' => ['first' => []],
          'column_widths' => $widths,
        ];
        break;
      case 'layout_twocol_section':
        $layout_type = 'layout_twocol_section';
        $layout['sections']["layout_twocol_section_$section_number"] = [
          'content' => [
            'first' => [],
            'second' => [],
          ],
          'column_widths' => $widths,
        ];
        break;
      case 'layout_threecol_section':
        $layout_type = 'layout_threecol_section';
        $layout['sections']["layout_threecol_section_$section_number"] = [
          'content' => [
            'first' => [],
            'second' => [],
            'third' => [],
          ],
          'column_widths' => $widths,
        ];
        break;
      case 'layout_fourcol_section':
        $layout_type = 'layout_fourcol_section';
        $layout['sections']["layout_fourcol_section_$section_number"] = [
          'content' => [
            'first' => [],
            'second' => [],
            'third' => [],
            'fourth' => [],
          ],
          'column_widths' => $widths,
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
        $layout['sections'][$layout_type . '_' .
        $section_number]['content'][$par["behavior_settings"][0]["value"]["layout_paragraphs"]["region"]][] = $content;
      }
    }
    return $layout;
  }

  private function getParagraphs($field_content_stuff): array {
    return array_map(function ($rs) {
      return json_decode($this->serializer->serialize($this->paragraph_service->load($rs['target_id']),
        'json',
        ['plugin_id' => 'paragraph']), TRUE);
    }, $field_content_stuff);
  }

  private function getStyles($field_styles): array {
    return array_map(function ($style) {
      return $style['value'];
    }, $field_styles);
  }

  private function getNode($slug) {
    $nids = $this->node_service->loadByProperties(['field_slug' => $slug]);
    $data = $this->node_service->load(array_keys($nids)[0]);
    return json_decode($this->serializer->serialize($data, 'json',
      ['plugin_id' => 'entity']), TRUE);
  }

  /**
   * @param string $string
   *
   * @return string
   */
  private function getUidFromString(string $string): string {
    return substr(base64_encode($string), 0, 20) ?? (string) rand(1, 1000);
  }


  private function getOneColumnLayout(array $paragraphs): array {
    $layout = [];
    foreach ($paragraphs as $par) {
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
      $layout[] = $content;
    }
    return $layout;
  }

}
