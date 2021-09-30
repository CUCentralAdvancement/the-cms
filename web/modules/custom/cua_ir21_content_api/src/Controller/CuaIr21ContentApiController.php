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
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Story data in JSON format.
   */
  public function stories(): JsonResponse {
    $nids = $this->node_service->loadByProperties(['type' => 'impact_story']);
    $nodes = array_map(function ($el) {
      return json_decode($this->serializer->serialize($el, 'json',
        ['plugin_id' => 'entity']), TRUE);
    }, $this->node_service->loadMultiple(array_keys($nids)));

    $result = [];
    foreach ($nodes as $node) {
      $result[] = [
        'title' => $node["title"][0]["value"],
        'slug' => $node["field_slug"][0]["value"],
        'body' => $node["body"][0]["value"],
        'main_image' => $this->getImageContent($node["field_image_main"][0]),
        'campus' => $node['field_campus'][0]['value'],
//        'layout' => $this->getLayout($this->getParagraphs($node["field_content_stuff"])),
        // 'layout' => $this->getOneColumnLayout($this->getParagraphs($node["field_content_stuff"])),
//        'related_stories' => $this->getRelatedStories($node['field_related_stories_2']),
      ];
    }

    return new JsonResponse($result);
  }

  /**
   * Builds the response.
   *
   * @param string $slug
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Story data in JSON format.
   */
  public function story(string $slug): JsonResponse {
    $node = $this->getNodeBySlug($slug);
    $result = [
      'title' => $node["title"][0]["value"],
      'slug' => $node["field_slug"][0]["value"],
      'body' => $node["body"][0]["value"],
      'main_image' => $this->getImageContent($node["field_image_main"][0]),
      'layout' => $this->getLayout($this->getParagraphs($node["field_content_stuff"])),
      // 'layout' => $this->getOneColumnLayout($this->getParagraphs($node["field_content_stuff"])),
      'related_stories' => $this->getRelatedStories($node['field_related_stories_2']),
    ];
    return new JsonResponse($result);
  }

  private function getLayout(array $paragraphs): array {
    $layout = [];
    $section_number = 0;
    $layout_type = '';
    foreach ($paragraphs as $par) {
      // If on a "layout paragraph", set a new section.
      if ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["layout"] !== '') {
        $this->setSections($section_number, $layout_type, $layout, $par);
      }

      // Otherwise, get content per paragraph type.
      if ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["region"] !== '') {
        $content = [];
        switch ($par["type"][0]["target_id"]) {
          case 'text_block':
            $content = $this->getTextBlockContent($par);
            break;
          case 'image':
            $content = $this->getParagraphImageContent($par);
            break;
          case 'numeric_stat':
            $content = $this->getNumericStatContent($par);
            break;
          case 'cta_block':
            $content = $this->getCtaBlockContent($par);
            break;
          case 'block_quote':
            $content = $this->getBlockQuoteContent($par);
            break;
          case 'social_links':
            $content = $this->getSocialLinksContent($par);
            break;
          case 'feedback_button':
            $content = $this->getFeedbackButtonContent($par);
            break;
          case 'content_list':
            $content = $this->getContentList($par);
            break;
        }
        $content['styles'] = $this->getStyles($par["field_styles"] ?? [], function($el) {
          return implode(' ', $el);
        });

        // Set content into proper layout sections.
        if ($layout_type === 'layout_onecol') {
          $layout['sections'][$layout_type . '_' . $section_number]['content'][] = $content;
        }
        else {
          $layout['sections'][$layout_type . '_' . $section_number]['content'][$par["behavior_settings"][0]["value"]["layout_paragraphs"]["region"]][] = $content;
        }
      }
    }
    return $layout;
  }

  private function getContentList(array $paragraph): array {
    $content = [];
    foreach ($paragraph['field_listing_item'] as $index => $item) {
      $data = $this->node_service->load($item['target_id']);
      $node = json_decode($this->serializer->serialize($data, 'json',
        ['plugin_id' => 'entity']), TRUE);
      $content[] = [
        'title' => $node['title'][0]['value'],
        'path' => $item['url'],
      ];
    }

    return [
      'content' => $content,
      'heading' => $paragraph['field_short_text'][0]['value'],
      'id' => $this->getUidFromString(),
      'type' => 'content_list',
    ];
  }

  private function getFeedbackButtonContent(array $paragraph): array {
    return [
      'action' => $paragraph['field_feedback_action'][0]['value'],
      'id' => $this->getUidFromString(),
      'type' => 'feedback_button',
    ];
  }

  private function getSocialLinksContent(array $paragraph): array {
    return [
      'services' => array_map(function ($el) {return $el['value'];}, $paragraph['field_services']),
      'id' => $this->getUidFromString(),
      'type' => 'social_links',
    ];
  }

  private function getBlockQuoteContent(array $paragraph): array {
    return [
      'quote' => strip_tags($paragraph['field_content'][0]["value"]),
      'id' => $this->getUidFromString($paragraph['field_content'][0]["value"]),
      'type' => 'block_quote',
    ];
  }

  private function getCtaBlockContent(array $paragraph): array {
    return [
      'heading' => $paragraph['field_short_text'][0]["value"],
      'button' => [
        'text' => $paragraph['field_link'][0]['title'],
        'url' => $paragraph['field_link'][0]['uri'],
      ],
      'content' => $paragraph['field_content'][0]["value"],
      'id' => $this->getUidFromString($paragraph['field_content'][0]["value"]),
      'type' => 'cta_block',
    ];
  }

  private function getTextBlockContent(array $paragraph): array {
    return [
      'content' => $paragraph['field_content'][0]["value"],
      'id' => $this->getUidFromString($paragraph['field_content'][0]["value"]),
      'type' => 'text_block',
    ];
  }

  private function getParagraphImageContent(array $paragraph): array {
    $image = $paragraph['field_image'][0];
    return $this->getImageContent($image);
  }

  private function getImageContent($image) {
    $file = $this->file_service->load($image["target_id"]);
    $file_stuff = json_decode($this->serializer->serialize($file, 'json',
      ['plugin_id' => 'file']), TRUE);
    return [
      'alt' => $image["alt"],
      'caption' => $image["title"],
      'width' => $image["width"],
      'height' => $image["height"],
      'url' => $file_stuff["uri"][0]["url"],
      'id' => $this->getUidFromString($image["alt"] . $file_stuff["uri"][0]["url"]),
      'type' => 'image',
    ];
  }

  private function getNumericStatContent(array $paragraph): array {
    return [
      'number' => $paragraph['field_number'][0]["value"],
      'label' => $paragraph['field_short_text'][0]["value"],
      'id' => $this->getUidFromString($paragraph['field_number'][0]["value"] . $paragraph['field_short_text'][0]["value"]),
      'type' => 'numeric_stat',
    ];
  }

  private function setSections(int &$section_number, string &$layout_type, array &$layout, array $par) {
    $section_number++;
    $widths = $par["behavior_settings"][0]["value"]["layout_paragraphs"]["config"]["column_widths"] ?? '100';
    $styles = $this->getStyles($par["field_layout_styles"], function($el) {
      return implode(' ', $el);
    }) ?? '';

    switch ($par["behavior_settings"][0]["value"]["layout_paragraphs"]["layout"]) {
      case 'layout_onecol':
        $layout_type = 'layout_onecol';
        $layout['sections']["layout_onecol_$section_number"] = [
          'content' => [],
          'column_widths' => $widths,
          'type' => 'one-column',
          'styles' => $styles,
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
          'type' => 'two-columns',
          'styles' => $styles,
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
          'type' => 'three-columns',
          'styles' => $styles,
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
          'type' => 'four-columns',
          'styles' => $styles,
        ];
        break;
    }
  }

  private function getParagraphs($field_content_stuff): array {
    return array_map(function ($rs) {
      return json_decode($this->serializer->serialize($this->paragraph_service->load($rs['target_id']),
        'json',
        ['plugin_id' => 'paragraph']), TRUE);
    }, $field_content_stuff);
  }

  private function getStyles(array $field_styles, $formatter) {
    $the_styles =  array_map(function ($style) {
      return $style['value'];
    }, $field_styles);
    return $formatter !== null ? call_user_func($formatter, $the_styles) : $the_styles;
  }

  private function getNodeBySlug($slug): array {
    $nids = $this->node_service->loadByProperties(['field_slug' => $slug]);
    $data = $this->node_service->load(array_keys($nids)[0]);
    return json_decode($this->serializer->serialize($data, 'json',
      ['plugin_id' => 'entity']), TRUE);
  }

  private function getNodesByIds(array $ids): array {
    return array_map(function ($el) {
      return json_decode($this->serializer->serialize($el, 'json',
        ['plugin_id' => 'entity']), TRUE);
    }, $this->node_service->loadMultiple($ids));
  }

  /**
   * @param string $string
   *
   * @return string
   */
  private function getUidFromString(string $string = ''): string {
    if ($string !== '') {
      return substr(base64_encode($string), 0, 20);
    } else {
      return (string) rand(1, 1000);
    }
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
          $content = $this->getParagraphImageContent($par["field_image"]);
          break;
      }
      $content['styles'] = $this->getStyles($par["field_styles"], null);
      $layout[] = $content;
    }
    return $layout;
  }

  private function getRelatedStories($related_stories) {
    $nodes = $this->getNodesByIds(array_map(function ($el) {
      return $el['target_id'];
    }, $related_stories));

    return array_map(function ($el) {
      return [
        'campus' => $el['field_campus'][0]['value'],
        'title' => $el['title'][0]['value'],
        'description' => strip_tags($el['body'][0]['processed']),
        'main_image' => $this->getImageContent($el['field_image_main'][0]),
        'slug' => $el['field_slug'][0]['value'],
      ];
    }, $nodes);
  }

}
