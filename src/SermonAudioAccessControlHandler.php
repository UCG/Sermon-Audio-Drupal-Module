<?php

declare(strict_types = 1);

namespace Drupal\calendar_item;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for sermon audio entities.
 */
class SermonAudioAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) : AccessResultInterface {
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view sermon audio entity');

      case 'edit':
        return AccessResult::allowedIfHasPermission($account, 'edit sermon audio entity');

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete sermon audio entity');

      default:
        // Don't opine if we don't understand the operation.
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) : AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'add sermon audio entity');
  }

}
