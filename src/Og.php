<?php

/**
 * @file
 * Contains \Drupal\og\Og.
 */

namespace Drupal\og;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\og\Plugin\EntityReferenceSelection\OgSelection;

/**
 * A static helper class for OG.
 */
class Og {

  /**
   * Static cache for groups per entity.
   *
   * @var array
   */
  protected static $entityGroupCache = [];

  /**
   * Create an organic groups field in a bundle.
   *
   * @param $entity_type
   *   The entity type.
   * @param $bundle
   *   The bundle name.
   * @param $field_name
   *   The field name.
   */
  public static function createField($entity_type, $bundle, $field_name = OG_AUDIENCE_FIELD) {
    $og_field = static::fieldInfo($field_name)
      ->setEntityType($entity_type)
      ->setBundle($bundle);

    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      $og_field->fieldDefinition()->save();
    }

    // Allow overriding the field name.
    // todo: ask if we need this.
//    $og_field['field']['field_name'] = $field_name;
//    if (empty($field)) {
//      $og_field['field']->save();
//    }

    if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      $og_field->instanceDefinition()->save();
      // Clear the entity property info cache, as OG fields might add different
      // entity property info.
      static::invalidateCache();
    }

    $form_display_storage = \Drupal::entityTypeManager()->getStorage('entity_form_display');
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $displayForm */
    if (!$displayForm = $form_display_storage->load($entity_type . '.' . $bundle . '.default')) {

      $values = [
        'targetEntityType' => $entity_type,
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ];

      $displayForm = $form_display_storage->create($values);
    }

    // Add the field to the form display manager.
    if (!$displayForm->getComponent($field_name) && $widgetDefinition = $og_field->widgetDefinition()) {
      $displayForm->setComponent($field_name, $widgetDefinition);
      // todo: fix when we handling the form widget.
//      $displayForm->save();
    }

    // Define the view mode for the field.
    if ($field_view_modes = $og_field->viewModesDefinition()) {
      $prefix = $entity_type . '.' . $bundle . '.';
      $view_modes = \Drupal::entityTypeManager()->getStorage('entity_view_display')->loadMultiple(array_keys($field_view_modes));

      foreach ($view_modes as $key => $view_mode) {
        /** @var \Drupal\Core\Entity\Display\EntityDisplayInterface $view_mode  */
        $view_mode->setComponent($field_name, $field_view_modes[$prefix . $key])->save();
      }
    }
  }

  /**
   * Gets the groups an entity is associated with.
   *
   * @param $entity_type
   *   The entity type.
   * @param $entity_id
   *   The entity ID.
   * @param $states
   *   (optional) Array with the state to return. Defaults to active.
   * @param $field_name
   *   (optional) The field name associated with the group.
   *
   * @return array
   *  An array with the group's entity type as the key, and array - keyed by
   *  the OG membership ID and the group ID as the value. If nothing found,
   *  then an empty array.
   */
  public static function getEntityGroups($entity_type, $entity_id, $states = [OG_STATE_ACTIVE], $field_name = NULL) {
    // Get a string identifier of the states, so we can retrieve it from cache.
    if ($states) {
      sort($states);
      $state_identifier = implode(':', $states);
    }
    else {
      $state_identifier = FALSE;
    }

    $identifier = [
      $entity_type,
      $entity_id,
      $state_identifier,
      $field_name,
    ];

    $identifier = implode(':', $identifier);
    if (isset(static::$entityGroupCache[$identifier])) {
      // Return cached values.
      return static::$entityGroupCache[$identifier];
    }

    static::$entityGroupCache[$identifier] = [];
    $query = \Drupal::entityQuery('og_membership')
      ->condition('entity_type', $entity_type)
      ->condition('etid', $entity_id);

    if ($states) {
      $query->condition('state', $states, 'IN');
    }

    if ($field_name) {
      $query->condition('field_name', $field_name);
    }

    $results = $query->execute();

    /** @var \Drupal\og\Entity\OgMembership[] $memberships */
    $memberships = \Drupal::entityTypeManager()
      ->getStorage('og_membership')
      ->loadMultiple($results);

    /** @var \Drupal\og\Entity\OgMembership $membership */
    foreach ($memberships as $membership) {
      static::$entityGroupCache[$identifier][$membership->getGroupType()][$membership->id()] = $membership->getGroup();
    }

    return static::$entityGroupCache[$identifier];
  }

  /**
   * Check if the given entity type / bundle can belong to a group.
   *
   * @param string $entity_type_id
   *   The entity type to check.
   * @param string $bundle
   *   The bundle to check.
   *
   * @return bool
   *   TRUE if the group audience field is present on the bundle.
   */
  public static function isGroupContent($entity_type_id, $bundle) {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type_id, $bundle);
    foreach ($field_definitions as $field_definition) {
      if (static::isGroupAudienceField($field_definition)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if the given entity is a group.
   *
   * @param string $entity_type_id
   * @param string $bundle
   *
   * @return bool
   *   True or false if the given entity is group.
   */
  public static function isGroup($entity_type_id, $bundle_id) {
    return static::groupManager()->isGroup($entity_type_id, $bundle_id);
  }

  /**
   * Sets an entity type instance as being an OG group.
   *
   * @param string $entity_type_id
   * @param string $bundle_id
   */
  public static function addGroup($entity_type_id, $bundle_id) {
    return static::groupManager()->addGroup($entity_type_id, $bundle_id);
  }

  /**
   * Removes an entity type instance as being an OG group.
   *
   * @param string $entity_type_id
   * @param string $bundle_id
   */
  public static function removeGroup($entity_type_id, $bundle_id) {
    return static::groupManager()->removeGroup($entity_type_id, $bundle_id);
  }

  /**
   * Return TRUE if field is a group audience type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field config object.
   *
   * @return bool
   *   TRUE if the field is a group audience type, FALSE otherwise.
   */
  public static function isGroupAudienceField(FieldDefinitionInterface $field_definition) {
    return $field_definition->getType() === 'og_membership_reference';
  }

  /**
   * Returns the group manager instance.
   *
   * @return \Drupal\og\GroupManager
   */
  public static function groupManager() {
    // @todo store static reference for this?
    return \Drupal::service('og.group.manager');
  }

  /**
   * Invalidate cache.
   *
   * @param $group_ids
   *   Array with group IDs that their cache should be invalidated.
   */
  public static function invalidateCache($group_ids = array()) {
    // @todo We should not be using drupal_static() review and remove.
    // Reset static cache.
    $caches = array(
      'og_user_access',
      'og_user_access_alter',
      'og_role_permissions',
      'og_get_user_roles',
      'og_get_permissions',
      'og_get_group_audience_fields',
      'og_get_entity_groups',
      'og_get_membership',
      'og_get_field_og_membership_properties',
      'og_get_user_roles',
    );

    foreach ($caches as $cache) {
      drupal_static_reset($cache);
    }

    // @todo Consider using a reset() method.
    static::$entityGroupCache = [];

    // Invalidate the entity property cache.
    \Drupal::entityManager()->clearCachedDefinitions();
    \Drupal::entityManager()->clearCachedFieldDefinitions();

    // Let other OG modules know we invalidate cache.
    \Drupal::moduleHandler()->invokeAll('og_invalidate_cache', $group_ids);
  }

  /**
   * Gets the storage manage for the OG membership entity.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   */
  public static function membershipStorage() {
    return \Drupal::entityManager()->getStorage('og_membership');
  }

  /**
   * Gets the default constructor parameters for OG membership.
   */
  public static function membershipDefault() {
    return ['type' => 'og_membership_type_default'];
  }

  /**
   * Get all the modules fields that can be assigned to fieldable entities.
   *
   * @param $field_name
   *   The field name that was registered for the definition.
   *
   * @return OgFieldBase|bool
   *   An array with the field and instance definitions, or FALSE if not.
   *
   * todo: pass the entity type and entity bundle to plugin definition.
   */
  protected static function fieldInfo($field_name = NULL) {
    $config = \Drupal::service('plugin.manager.og.fields');
    $fields_config = $config->getDefinitions();

    if ($field_name) {
      return isset($fields_config[$field_name]) ? $config->createInstance($field_name) : NULL;
    }

    return $fields_config;
  }

  /**
   * Get the selection handler for an audience field attached to entity.
   *
   * @param $entity
   *   The entity type.
   * @param $bundle
   *   The bundle name.
   * @param $field_name
   *   The field name.
   * @param array $options
   *   Overriding the default options of the selection handler.
   *
   * @return OgSelection
   * @throws \Exception
   */
  public static function getSelectionHandler($entity, $bundle, $field_name, array $options = []) {
    $field_definition = FieldConfig::loadByName($entity, $bundle, $field_name);

    if (!static::isGroupAudienceField($field_definition)) {
      throw new \Exception(new FormattableMarkup('The field @name is not an audience field.', ['@name' => $field_name]));
    }

    $options += [
      'target_type' => $field_definition->getFieldStorageDefinition()->getSetting('target_type'),
      'field' => $field_definition,
      'handler' => $field_definition->getSetting('handler'),
      'handler_settings' => [],
    ];

    // Deep merge the handler settings.
    $options['handler_settings'] = NestedArray::mergeDeep($field_definition->getSetting('handler_settings'), $options['handler_settings']);

    return \Drupal::service('plugin.manager.entity_reference_selection')->createInstance('og:default', $options);
  }

}
