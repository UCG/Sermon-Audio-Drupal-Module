<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

/**
 * References a repository of file "extensions" used for file rename requests.
 *
 * It is difficult to rename files on upload unless you are willing to have the
 * file moved twice (once from the temp directory, and again to the final
 * location with the final name). To avoid this inefficiency in a relatively
 * robust manner, one can add a fake random "allowed extension" when uploading
 * the file, obtained by calling addBareFilename() with the bare
 * (extension-less) filename one wishes to make the final filename. Then, when
 * @see \Drupal\Core\File\Event\FileUploadSanitizeNameEvent is fired, one can
 * subscribe to that event and determine if any of the allowed extensions passed
 * to the handler correspond to an extension in this repository: if so, the
 * handler can rename the file in accordance with the new bare filename (which
 * would be linked in this repository to the dummy allowed extension).
 *
 * The repository itself is stored statically, not per class instance.
 */
class FileRenamePseudoExtensionRepository {

  /**
   * Adds a new bare filename (to which a file should be renamed) to the repo.
   *
   * @param string $bareFilename
   *   Bare (extension-less) filename to add.
   *
   * @return string
   *   New pseudo-extension (alphanumeric string of length 16) associated with
   *   bare filename.
   */
  public function addBareFilename(string $bareFilename) : string {
    // 64 bits should be sufficient entropy.
    $extension = bin2hex(random_bytes(8));
    static::getNewBareFilenames()[$extension] = $bareFilename;
    return $extension;
  }

  /**
   * Removes a pseudo-extension entry (with its filename) from the repository.
   *
   * @param string $pseudoExtension
   *   Pseudo-extension to remove.
   *
   * @return bool
   *   TRUE if the extension was found in the repository and removed; FALSE if
   *   the extension was not found.
   */
  public function removePseudoExtension(string $pseudoExtension) : bool {
    $filenames =& static::getNewBareFilenames();
    if (array_key_exists($pseudoExtension, $filenames)) {
      unset($filenames[$pseudoExtension]);
      return TRUE;
    }
    else return FALSE;
  }

  /**
   * Attempts to get a bare filename from the repo, given a list of extensions.
   *
   * @param iterable<string> $extensions
   *   Extensions to test.
   *
   * @return string|null
   *   The bare (extension-less) filename in the repo associated with the first
   *   extension in $extensions that matches an extension in the repo, or NULL
   *   if no such bare filename is found.
   *
   * @throws \InvalidArgumentException
   *   Thrown if an element in $extensions is not a string.
   */
  public function tryGetBareFilename(iterable $extensions) : ?string {
    $filenames =& static::getNewBareFilenames();
    foreach ($extensions as $extension) {
      if (!is_string($extension)) {
        throw new \InvalidArgumentException('An extension in $extensions is not a string.');
      }
      if (array_key_exists($extension, $filenames)) {
        return $filenames[$extension];
      }
    }

    return NULL;
  }

  /**
   * Gets the new bare (extension-less) filename vs. dummy extension table.
   *
   * @return array
   *   Reference to an array, whose keys are the dummy extensions and whose
   *   values are the corresponding new bare filenames.
   */
  private static function &getNewBareFilenames() : array {
    return drupal_static('sermon_audio_new_bare_filenames_table', []);
  }

}
