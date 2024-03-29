<?php

namespace Drupal\cua_giving_content_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Returns responses for cua_giving_content_api routes.
 */
class CuaGivingContentApiController extends ControllerBase {

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $node_service;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $taxonomy_service;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private RequestStack $request_stack;

  /**
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  private SerializerInterface $serializer;

  public function __construct(RequestStack $request_stack, SerializerInterface $serializer) {
    $this->serializer = $serializer;
    $this->node_service = $this->entityTypeManager()->getStorage('node');
    $this->taxonomy_service = $this->entityTypeManager()->getStorage('taxonomy_term');
    $this->request_stack = $request_stack;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('serializer'),
    );
  }

  /**
   * Builds the response.
   *
   * @param string $slug
   *   Short human-readable name of fund.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Fund data in JSON format.
   *
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
   *   List of funds as data in JSON format.
   *
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
   * Builds the response.
   *
   * @param string $slug
   *   Short human-readable name of fund.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Fund data in JSON format.
   *
   */
  public function faq(string $slug): JsonResponse {
    $nids = $this->node_service->loadByProperties(['field_slug' => "faq/$slug"]);
    $data = $this->node_service->load(array_keys($nids)[0]);
    $node = json_decode($this->serializer->serialize($data, 'json', ['plugin_id' => 'entity']), TRUE);

    // Direct fields.
    $faq = [];
    $faq['question'] = $node["title"][0]["value"];
    $faq['slug'] = $node["field_slug"][0]["value"];
    $faq['answer'] = $node["body"][0]["value"];
    $faq['detailed_question'] = $node["field_detailed_question"][0]["value"];
    $faq['category'] = $node["field_category"][0]["value"];

    return new JsonResponse($faq);
  }

  /**
   * Dd.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   List of funds as data in JSON format.
   *
   */
  public function faqs(): JsonResponse {
    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'faq')
      ->sort('field_category', 'ASC')
      ->execute();
    $nodes = $this->node_service->loadMultiple($nids);
    $data = json_decode($this->serializer->serialize($nodes, 'json', ['plugin_id' => 'entity']), TRUE);

    $faqs = [];
    foreach ($data as $faq) {
      $faqs[$faq["field_category"][0]["value"]][] = [
        'question' => $faq["title"][0]["value"],
        'slug' => explode('/', $faq["field_slug"][0]["value"])[1],
        'answer' => $faq["body"][0]["value"],
        'detailed_question' => $faq["field_detailed_question"][0]["value"],
        'category' => $faq["field_category"][0]["value"],
      ];
    }

    return new JsonResponse($faqs);
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
      $paths[] = $node["field_slug"][0]["value"];
    }

    return new JsonResponse($paths);
  }

  /**
   * Flushes all caches.
   */
  public function flushAll() {
    $this->messenger()->addMessage($this->t('All caches cleared.'));
    drupal_flush_all_caches();
    return new RedirectResponse($this->reloadPage());
  }

  /**
   * Reload the previous page.
   */
  public function reloadPage() {
    $request = $this->request_stack->getCurrentRequest();
    if ($request->server->get('HTTP_REFERER')) {
      return $request->server->get('HTTP_REFERER');
    }
    else {
      return base_path();
    }
  }

}
