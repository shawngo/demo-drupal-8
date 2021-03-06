<?php

/**
 * @file
 * Contains \Drupal\taxonomy\Entity\Vocabulary.
 */

namespace Drupal\taxonomy\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\taxonomy\VocabularyInterface;

/**
 * Defines the taxonomy vocabulary entity.
 *
 * @ConfigEntityType(
 *   id = "taxonomy_vocabulary",
 *   label = @Translation("Taxonomy vocabulary"),
 *   handlers = {
 *     "storage" = "Drupal\taxonomy\VocabularyStorage",
 *     "list_builder" = "Drupal\taxonomy\VocabularyListBuilder",
 *     "form" = {
 *       "default" = "Drupal\taxonomy\VocabularyForm",
 *       "reset" = "Drupal\taxonomy\Form\VocabularyResetForm",
 *       "delete" = "Drupal\taxonomy\Form\VocabularyDeleteForm"
 *     }
 *   },
 *   admin_permission = "administer taxonomy",
 *   config_prefix = "vocabulary",
 *   bundle_of = "taxonomy_term",
 *   entity_keys = {
 *     "id" = "vid",
 *     "label" = "name",
 *     "weight" = "weight"
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/add",
 *     "delete-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/delete",
 *     "reset-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/reset",
 *     "overview-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/overview",
 *     "edit-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}",
 *     "collection" = "/admin/structure/taxonomy",
 *   }
 * )
 */
class Vocabulary extends ConfigEntityBundleBase implements VocabularyInterface {

  /**
   * The taxonomy vocabulary ID.
   *
   * @var string
   */
  protected $vid;

  /**
   * Name of the vocabulary.
   *
   * @var string
   */
  protected $name;

  /**
   * Description of the vocabulary.
   *
   * @var string
   */
  protected $description;

  /**
   * The type of hierarchy allowed within the vocabulary.
   *
   * Possible values:
   * - TAXONOMY_HIERARCHY_DISABLED: No parents.
   * - TAXONOMY_HIERARCHY_SINGLE: Single parent.
   * - TAXONOMY_HIERARCHY_MULTIPLE: Multiple parents.
   *
   * @var integer
   */
  protected $hierarchy = TAXONOMY_HIERARCHY_DISABLED;

  /**
   * The weight of this vocabulary in relation to other vocabularies.
   *
   * @var integer
   */
  protected $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function getHierarchy() {
    return $this->hierarchy;
  }

  /**
   * {@inheritdoc}
   */
  public function setHierarchy($hierarchy) {
    $this->hierarchy = $hierarchy;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->vid;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    if ($update && $this->getOriginalId() != $this->id() && !$this->isSyncing()) {
      // Reflect machine name changes in the definitions of existing 'taxonomy'
      // fields.
      $field_ids = array();
      $field_map = \Drupal::entityManager()->getFieldMapByFieldType('entity_reference');
      foreach ($field_map as $entity_type => $field_storages) {
        foreach ($field_storages as $field_storage => $info) {
          $field_ids[] = $entity_type . '.' . $field_storage;
        }
      }

      $field_storages = \Drupal::entityManager()->getStorage('field_storage_config')->loadMultiple($field_ids);
      $taxonomy_fields = array_filter($field_storages, function ($field_storage) {
        return $field_storage->getType() == 'entity_reference' && $field_storage->getSetting('target_type') == 'taxonomy_term';
      });

      foreach ($taxonomy_fields as $field_storage) {
        $update_storage = FALSE;

        $allowed_values = $field_storage->getSetting('allowed_values');
        foreach ($allowed_values as &$value) {
          if ($value['vocabulary'] == $this->getOriginalId()) {
            $value['vocabulary'] = $this->id();
            $update_storage = TRUE;
          }
        }
        $field_storage->setSetting('allowed_values', $allowed_values);

        if ($update_storage) {
          $field_storage->save();
        }
      }
    }
    $storage->resetCache($update ? array($this->getOriginalId()) : array());
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);

    // Only load terms without a parent, child terms will get deleted too.
    entity_delete_multiple('taxonomy_term', $storage->getToplevelTids(array_keys($entities)));
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    // Reset caches.
    $storage->resetCache(array_keys($entities));

    if (reset($entities)->isSyncing()) {
      return;
    }

    $vocabularies = array();
    foreach ($entities as $vocabulary) {
      $vocabularies[$vocabulary->id()] = $vocabulary->id();
    }
    // Load all Taxonomy module fields and delete those which use only this
    // vocabulary.
    $field_storages = entity_load_multiple_by_properties('field_storage_config', array('module' => 'taxonomy'));
    foreach ($field_storages as $field_storage) {
      $modified_storage = FALSE;
      // Term reference fields may reference terms from more than one
      // vocabulary.
      foreach ($field_storage->getSetting('allowed_values') as $key => $allowed_value) {
        if (isset($vocabularies[$allowed_value['vocabulary']])) {
          $allowed_values = $field_storage->getSetting('allowed_values');
          unset($allowed_values[$key]);
          $field_storage->setSetting('allowed_values', $allowed_values);
          $modified_storage = TRUE;
        }
      }
      if ($modified_storage) {
        $allowed_values = $field_storage->getSetting('allowed_values');
        if (empty($allowed_values)) {
          $field_storage->delete();
        }
        else {
          // Update the field definition with the new allowed values.
          $field_storage->save();
        }
      }
    }
  }

}
