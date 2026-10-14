<?php

declare(strict_types=1);

namespace Drupal\icms_core_logic;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\paragraphs_blokkli\ParagraphsBlokkliConfig;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Opens the blökkli editor of the frontend from the Drupal backend.
 *
 * The editor is the frontend page with the entity's blökkli query
 * (?blokkliEditing=<uuid>&language=<langcode>); it needs the whole window, so
 * links to it leave the site shell.
 */
final class BlokkliEditor {

  public const PERMISSION = 'edit paragraphs blokkli edit state';

  public function __construct(
    private readonly ParagraphsBlokkliConfig $blokkliConfig,
    private readonly BackendDomain $backendDomain,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Whether blökkli is enabled for the entity's type.
   */
  public function isEnabled(EntityInterface $entity): bool {
    return $this->blokkliConfig->isEnabledForEntity($entity);
  }

  /**
   * Whether an entity can be edited with blökkli by an account.
   */
  public function access(EntityInterface $entity, ?AccountInterface $account = NULL): AccessResultInterface {
    $account ??= $this->currentUser;
    return AccessResult::allowedIf($this->isEnabled($entity))
      ->addCacheTags(['config:paragraphs_blokkli.settings'])
      ->andIf(AccessResult::allowedIfHasPermission($account, self::PERMISSION))
      ->andIf($entity->access('update', $account, TRUE));
  }

  /**
   * The editor URL of an entity in the current content language.
   *
   * On a backend request the editor is on the frontend of that domain;
   * elsewhere (Drush) the URL is relative.
   */
  public function getUrl(EntityInterface $entity): Url {
    $entity = $this->entityRepository->getTranslationFromContext($entity);
    $path = $entity->toUrl()->toString(TRUE)->getGeneratedUrl();
    $query = [
      'blokkliEditing' => $entity->uuid(),
      'language' => $entity->language()->getId(),
    ];
    $request = $this->requestStack->getCurrentRequest();
    $frontendUrl = $request ? $this->backendDomain->getFrontendUrl($request) : NULL;
    return Url::fromUri($frontendUrl ? $frontendUrl . $path : 'base:' . ltrim($path, '/'), ['query' => $query]);
  }

}
