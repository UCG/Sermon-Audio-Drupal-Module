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
 * pseudo-extensions registered in
 * @see \Drupal\sermon_audio\FileRenamePseudoExtensionRepository,
 * and using a matching psuedo-extension to create a new filename.
 */
class UploadedFileRenameSubscriber implements EventSubscriberInterface {

  /**
   * Repository containing possible new (post-rename) extension-less filenames.
   */
  private readonly FileRenamePseudoExtensionRepository $newFilenamesRepository;

  /**
   * Creates a new upload file rename subscriber.
   *
   * @param \Drupal\sermon_audio\FileRenamePseudoExtensionRepository $newFilenamesRepository
   *   Repository containing possible new (post-rename) extension-less filenames.
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
    // Get the new extension-less filename, if applicable.
    $bareNewFilename = $this->newFilenamesRepository->tryGetBareFilename($event->getAllowedExtensions());
    if ($bareNewFilename !== NULL) {
      // Preserve the current extension.
      $extension = pathinfo($event->getFilename(), PATHINFO_EXTENSION);
      $event->setFilename($bareNewFilename . '.' . $extension);
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
