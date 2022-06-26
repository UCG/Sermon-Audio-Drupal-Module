<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\EventSubscriber;

use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\sermon_audio\FileRenamePseudoExtensionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Renames files on upload.
 *
 * Uploaded files are renamed by checking the allowed extension list against
 * names associated with pseudo-extensions registered in
 * @see \Drupal\sermon_audio\FileRenamePseudoExtensionRepository.
 */
class UploadedFileRenameSubscriber implements EventSubscriberInterface {

  /**
   * Repository containing possible new (post-rename) filenames.
   */
  private FileRenamePseudoExtensionRepository $newFilenamesRepository;

  /**
   * Creates a new upload file rename subscriber.
   *
   * @param \Drupal\sermon_audio\FileRenamePseudoExtensionRepository $newFilenamesRepository
   *   Repository containing possible new (post-rename) filenames.
   */
  public function __construct(FileRenamePseudoExtensionRepository $newFilenamesRepository) {
    $this->newFilenamesRepository = $newFilenamesRepository;
  }

  /**
   * Handles the "sanitize filename" event.
   *
   * @param \Drupal\Core\File\Event\FileUploadSanitizeNameEvent $event
   *   Event.
   */
  public function handleSanitizeName(FileUploadSanitizeNameEvent $event) {
    // Get the new filename, if applicable.
    $newFilename = $this->newFilenamesRepository->tryGetFilename($event->getAllowedExtensions());
    if ($newFilename !== NULL) {
      $event->setFilename($newFilename)->setSecurityRename(FALSE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      FileUploadSanitizeNameEvent::class => [['handleSanitizeName']],
    ];
  }

}
