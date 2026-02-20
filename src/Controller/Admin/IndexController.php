<?php

declare(strict_types=1);

namespace FirstMediaVisibility\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Entity\Media;
use Omeka\Form\ConfirmForm;

/**
 * Admin UI controller for first-media visibility operations.
 */
class IndexController extends AbstractActionController {

  /**
   * DB connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  private Connection $connection;

  /**
   * Doctrine entity manager.
   *
   * @var \Doctrine\ORM\EntityManager
   */
  private EntityManager $entityManager;

  public function __construct(Connection $connection, EntityManager $entityManager) {
    $this->connection = $connection;
    $this->entityManager = $entityManager;
  }

  /**
   * Display the paginated and sortable item list.
   */
  public function indexAction(): ViewModel {
    $page = max(1, (int) $this->params()->fromQuery('page', 1));
    $perPage = (int) $this->params()->fromQuery('per_page', 50);
    $filterText = trim((string) $this->params()->fromQuery('q', ''));
    $identifierText = trim((string) $this->params()->fromQuery('identifiers_text', ''));
    $visibilityFilter = trim((string) $this->params()->fromQuery('visibility', 'all'));
    $preferredSiteSlug = trim((string) $this->params()->fromQuery('site_slug', ''));
    $siteSlugs = [];
    $siteSlugRows = $this->connection->fetchFirstColumn("SELECT slug FROM site WHERE slug IS NOT NULL AND slug != '' ORDER BY slug ASC");
    foreach ($siteSlugRows as $slug) {
      $slug = trim((string) $slug);
      if ($slug !== '') {
        $siteSlugs[] = $slug;
      }
    }
    $siteSlugs = array_values(array_unique($siteSlugs));
    if ($preferredSiteSlug !== '' && !in_array($preferredSiteSlug, $siteSlugs, TRUE)) {
      $preferredSiteSlug = '';
    }
    if ($perPage <= 0) {
      $perPage = 50;
    }
    $perPage = min(1000, $perPage);

    $sort = (string) $this->params()->fromQuery('sort', 'item_title');
    if (!in_array($sort, ['item_title', 'media_title', 'modified', 'visibility'], TRUE)) {
      $sort = 'item_title';
    }
    if (!in_array($visibilityFilter, ['all', 'public', 'private'], TRUE)) {
      $visibilityFilter = 'all';
    }
    $order = strtolower((string) $this->params()->fromQuery('order', 'asc'));
    $order = $order === 'desc' ? 'DESC' : 'ASC';

    $offset = ($page - 1) * $perPage;

    $whereParts = [];
    $filterParams = [];
    $filterTypes = [];
    if ($filterText !== '') {
      $whereParts[] = "(ri.title LIKE :filter OR COALESCE(NULLIF(rm.title, ''), fm.source, '') LIKE :filter)";
      $filterParams['filter'] = '%' . $filterText . '%';
      $filterTypes['filter'] = \PDO::PARAM_STR;
    }

    if ($visibilityFilter === 'public') {
      $whereParts[] = 'rm.is_public = 1';
    }
    elseif ($visibilityFilter === 'private') {
      $whereParts[] = 'rm.is_public = 0';
    }

    $identifierTokens = $this->parseIdentifiers($identifierText);
    $identifierItemIds = $this->resolveItemIdsForTokens($identifierTokens);
    if (!empty($identifierTokens)) {
      if (empty($identifierItemIds)) {
        $whereParts[] = '1 = 0';
      }
      else {
        $idPlaceholders = [];
        foreach ($identifierItemIds as $index => $identifierItemId) {
          $paramName = 'identifier_item_id_' . $index;
          $idPlaceholders[] = ':' . $paramName;
          $filterParams[$paramName] = (int) $identifierItemId;
          $filterTypes[$paramName] = \PDO::PARAM_INT;
        }
        $whereParts[] = 'i.id IN (' . implode(', ', $idPlaceholders) . ')';
      }
    }

    $whereSql = '';
    if (!empty($whereParts)) {
      $whereSql = "\n      WHERE " . implode(' AND ', $whereParts);
    }

    $countSql = "
      SELECT COUNT(*)
      FROM item i
      INNER JOIN resource ri
        ON ri.id = i.id
      LEFT JOIN media fm
        ON fm.id = (
          SELECT m2.id
          FROM media m2
          WHERE m2.item_id = i.id
          ORDER BY m2.position ASC, m2.id ASC
          LIMIT 1
        )
      LEFT JOIN resource rm
        ON rm.id = fm.id" . $whereSql;

    $total = (int) $this->connection->fetchOne($countSql, $filterParams, $filterTypes);

    $sortExpr = $sort === 'media_title'
      ? 'COALESCE(NULLIF(rm.title, \'\'), fm.source, \'\')'
      : ($sort === 'modified'
        ? 'COALESCE(ri.modified, ri.created)'
        : ($sort === 'visibility'
          ? 'COALESCE(rm.is_public, -1)'
          : 'COALESCE(ri.title, \'\')'));

    $sql = "
      SELECT
        i.id AS item_id,
        ri.title AS item_title,
        COALESCE(ri.modified, ri.created) AS item_modified,
        fm.id AS media_id,
        COALESCE(NULLIF(rm.title, ''), fm.source, '') AS media_title,
        rm.is_public AS media_is_public
      FROM item i
      INNER JOIN resource ri
        ON ri.id = i.id
      LEFT JOIN media fm
        ON fm.id = (
          SELECT m2.id
          FROM media m2
          WHERE m2.item_id = i.id
          ORDER BY m2.position ASC, m2.id ASC
          LIMIT 1
        )
      LEFT JOIN resource rm
        ON rm.id = fm.id
      {$whereSql}
      ORDER BY {$sortExpr} {$order}, i.id ASC
      LIMIT :limit OFFSET :offset
    ";

    $params = array_merge($filterParams, ['limit' => $perPage, 'offset' => $offset]);
    $types = array_merge($filterTypes, ['limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT]);

    $rows = $this->connection->fetchAllAssociative(
      $sql,
      $params,
      $types
    );

    $mediaIds = [];
    $itemIds = [];
    foreach ($rows as $row) {
      $itemIds[] = (int) ($row['item_id'] ?? 0);
      if (!empty($row['media_id'])) {
        $mediaIds[] = (int) $row['media_id'];
      }
    }
    $mediaIds = array_values(array_unique($mediaIds));
    $itemIds = array_values(array_unique(array_filter($itemIds)));

    $mediaRepresentations = [];
    if (!empty($mediaIds)) {
      $response = $this->api()->search('media', [
        'id' => $mediaIds,
        'limit' => count($mediaIds),
      ]);
      foreach ($response->getContent() as $media) {
        $mediaRepresentations[(int) $media->id()] = $media;
      }
    }

    $itemRepresentations = [];
    if (!empty($itemIds)) {
      $response = $this->api()->search('items', [
        'id' => $itemIds,
        'limit' => count($itemIds),
      ]);
      foreach ($response->getContent() as $item) {
        $itemRepresentations[(int) $item->id()] = $item;
      }
    }

    foreach ($rows as &$row) {
      $itemId = (int) ($row['item_id'] ?? 0);
      $mediaId = (int) ($row['media_id'] ?? 0);
      $row['item_title'] = trim((string) ($row['item_title'] ?? ''));
      if ($row['item_title'] === '') {
        $row['item_title'] = '[' . $this->translate('Untitled') . ']';
      }

      $row['public_item_url'] = '';
      if ($itemId > 0 && isset($itemRepresentations[$itemId])) {
        try {
          $itemRep = $itemRepresentations[$itemId];
          $siteSlug = '';
          $itemSiteSlugs = [];
          if (method_exists($itemRep, 'sites')) {
            $sites = $itemRep->sites();
            if (is_array($sites) && !empty($sites)) {
              foreach ($sites as $siteRep) {
                if ($siteRep && method_exists($siteRep, 'slug')) {
                  $slug = trim((string) ($siteRep->slug() ?? ''));
                  if ($slug !== '') {
                    $itemSiteSlugs[] = $slug;
                  }
                }
              }
            }
          }

          if ($preferredSiteSlug !== '') {
            if (in_array($preferredSiteSlug, $itemSiteSlugs, TRUE)) {
              $siteSlug = $preferredSiteSlug;
            }
          }
          elseif (!empty($itemSiteSlugs)) {
            $siteSlug = $itemSiteSlugs[0];
          }

          if ($siteSlug !== '') {
            $row['public_item_url'] = (string) ($itemRep->siteUrl($siteSlug) ?? '');
          }
        }
        catch (\Throwable $e) {
          $row['public_item_url'] = '';
        }
      }

      if ($mediaId > 0 && isset($mediaRepresentations[$mediaId])) {
        $media = $mediaRepresentations[$mediaId];
        $row['thumb_url'] = (string) ($media->thumbnailUrl('square') ?? '');
        $row['media_title'] = trim((string) ($row['media_title'] ?? ''));
        if ($row['media_title'] === '') {
          $row['media_title'] = '[' . $this->translate('No title') . ']';
        }
      }
      else {
        $row['thumb_url'] = '';
        $row['media_title'] = '[' . $this->translate('No media') . ']';
        $row['media_is_public'] = NULL;
      }
    }
    unset($row);

    $confirmForm = $this->getForm(ConfirmForm::class);

    return new ViewModel([
      'rows' => $rows,
      'total' => $total,
      'page' => $page,
      'perPage' => $perPage,
      'filterText' => $filterText,
      'identifierText' => $identifierText,
      'visibilityFilter' => $visibilityFilter,
      'siteSlug' => $preferredSiteSlug,
      'siteSlugs' => $siteSlugs,
      'sort' => $sort,
      'order' => strtolower($order),
      'confirmForm' => $confirmForm,
    ]);
  }

  /**
   * Parse identifiers from free text.
   */
  private function parseIdentifiers(string $text): array {
    $tokens = preg_split('/[\r\n,\t\s]+/u', $text) ?: [];
    $tokens = array_values(array_unique(array_filter(array_map(static function ($v) {
      return trim((string) $v);
    }, $tokens), static function ($v) {
      return $v !== '';
    })));
    return $tokens;
  }

  /**
   * Resolve identifier tokens into item IDs.
   */
  private function resolveItemIdsForTokens(array $tokens): array {
    $itemIds = [];
    foreach ($tokens as $token) {
      foreach ($this->resolveItemIds((string) $token) as $itemId) {
        $itemIds[] = (int) $itemId;
      }
    }
    return array_values(array_unique(array_filter($itemIds)));
  }

  /**
   * Resolve a token to one or more item ids.
   */
  private function resolveItemIds(string $token): array {
    $token = trim($token);
    if ($token === '') {
      return [];
    }

    if (ctype_digit($token)) {
      $id = (int) $token;
      $exists = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM item WHERE id = :id', ['id' => $id], ['id' => \PDO::PARAM_INT]);
      return $exists > 0 ? [$id] : [];
    }

    $ids = $this->connection->fetchFirstColumn(
      'SELECT DISTINCT i.id
       FROM item i
       INNER JOIN value v ON v.resource_id = i.id
       INNER JOIN property p ON p.id = v.property_id
       INNER JOIN vocabulary voc ON voc.id = p.vocabulary_id
       WHERE voc.prefix = :vocab_prefix
         AND p.local_name = :local_name
         AND (v.value = :token OR v.uri = :token)
       LIMIT 5',
      [
        'vocab_prefix' => 'dcterms',
        'local_name' => 'identifier',
        'token' => $token,
      ],
      [
        'vocab_prefix' => \PDO::PARAM_STR,
        'local_name' => \PDO::PARAM_STR,
        'token' => \PDO::PARAM_STR,
      ]
    );
    $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
    if (!empty($ids)) {
      return $ids;
    }

    $ids = $this->connection->fetchFirstColumn(
      'SELECT DISTINCT i.id
       FROM item i
       INNER JOIN value v ON v.resource_id = i.id
       WHERE v.value = :token OR v.uri = :token
       LIMIT 5',
      ['token' => $token],
      ['token' => \PDO::PARAM_STR]
    );
    $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
    if (!empty($ids)) {
      return $ids;
    }

    $ids = $this->connection->fetchFirstColumn(
      'SELECT DISTINCT m.item_id
       FROM media m
       WHERE m.source LIKE :token
       LIMIT 5',
      ['token' => '%' . $token . '%'],
      ['token' => \PDO::PARAM_STR]
    );
    return array_values(array_unique(array_map('intval', $ids ?: [])));
  }

  /**
   * Toggle visibility for the first media of an item.
   */
  public function toggleAction(): JsonModel {
    $request = $this->getRequest();
    $isPost = method_exists($request, 'isPost')
      ? $request->isPost()
      : (method_exists($request, 'getMethod') ? strtoupper((string) $request->getMethod()) === 'POST' : FALSE);
    if (!$isPost) {
      return new JsonModel(['ok' => FALSE, 'message' => $this->translate('Method not allowed.')]);
    }

    $form = $this->getForm(ConfirmForm::class);
    $post = $this->params()->fromPost();
    $form->setData($post);
    if (!$form->isValid()) {
      return new JsonModel(['ok' => FALSE, 'message' => $this->translate('Invalid CSRF token.')]);
    }

    $mediaId = (int) ($post['media_id'] ?? 0);
    if ($mediaId <= 0) {
      return new JsonModel(['ok' => FALSE, 'message' => $this->translate('Invalid media id.')]);
    }

    /** @var \Omeka\Entity\Media|null $media */
    $media = $this->entityManager->find(Media::class, $mediaId);
    if (!$media) {
      return new JsonModel(['ok' => FALSE, 'message' => $this->translate('Media not found.')]);
    }

    $item = $media->getItem();
    if (!$item) {
      return new JsonModel(['ok' => FALSE, 'message' => $this->translate('Item not found for media.')]);
    }

    $firstMediaId = (int) $this->connection->fetchOne(
      'SELECT id FROM media WHERE item_id = :item_id ORDER BY position ASC, id ASC LIMIT 1',
      ['item_id' => (int) $item->getId()],
      ['item_id' => \PDO::PARAM_INT]
    );

    if ($firstMediaId !== $mediaId) {
      return new JsonModel([
        'ok' => FALSE,
        'message' => $this->translate('The first media has changed. Please reload and try again.'),
      ]);
    }

    $newState = !$media->isPublic();
    $media->setIsPublic($newState);

    if ($newState) {
      // When the first media becomes public, make it primary.
      $item->setPrimaryMedia($media);
    }
    else {
      // When the first media becomes private, make the second media primary.
      $secondMediaId = (int) $this->connection->fetchOne(
        'SELECT id FROM media WHERE item_id = :item_id ORDER BY position ASC, id ASC LIMIT 1 OFFSET 1',
        ['item_id' => (int) $item->getId()],
        ['item_id' => \PDO::PARAM_INT]
      );
      if ($secondMediaId > 0) {
        /** @var \Omeka\Entity\Media|null $secondMedia */
        $secondMedia = $this->entityManager->find(Media::class, $secondMediaId);
        $item->setPrimaryMedia($secondMedia ?: NULL);
      }
      else {
        $item->setPrimaryMedia(NULL);
      }
    }

    $this->entityManager->flush();

    return new JsonModel([
      'ok' => TRUE,
      'is_public' => $newState ? 1 : 0,
      'icon_class' => $newState ? 'o-icon-public' : 'o-icon-private',
      'label' => $newState ? $this->translate('Public') : $this->translate('Private'),
    ]);
  }

}
