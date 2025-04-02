<?php

/**
 * @file
 * Hooks provided by the OCHA Content Classification module.
 */

/**
 * @addtogroup hooks
 * @{
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface;

/**
 * Respond to entity classification completion.
 *
 * This hook is invoked after an entity has been classified, allowing modules
 * to perform additional operations.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity that was classified.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow used for classification.
 * @param \Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface $classifier
 *   The classifier plugin that performed the classification.
 * @param array $updated_fields
 *   Fields of the entity that were updated during the classification.
 * @param array $data
 *   Data used for the classification. This depends on the classifier.
 *
 * @return bool
 *   Return TRUE to indicate that the module made changes that should be
 *   considered as an update to the entity.
 */
function hook_ocha_content_classification_post_classify_entity(
  EntityInterface $entity,
  ClassificationWorkflowInterface $workflow,
  ClassifierPluginInterface $classifier,
  array $updated_fields,
  array $data,
) {
  // Example: Log classification results.
  \Drupal::logger('my_module')->notice('Entity @id was classified with workflow @workflow', [
    '@id' => $entity->id(),
    '@workflow' => $workflow->id(),
  ]);

  // Example: Perform additional operations based on classification output.
  if (!empty(data['some_specific_data'])) {
    // Do something with the data.
    // Return the list of updated entity fields.
    return ['some_updated_field'];
  }

  // No changes made.
  return NULL;
}

/**
 * Alter the fields to check to proceed with the classification.
 *
 * This allows to bypass the check on the emptiness of a classifiable or
 * fillable field when determining if the classification should proceed.
 *
 * @param array $fields_to_check
 *   Associative array with the field names as keys and TRUE or FALSE as values.
 *   The check on a field is performed only if the value for the field is TRUE.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: The entity being classified.
 */
function hook_ocha_content_classification_specified_field_check_alter(
  array &$fields_to_check,
  ClassificationWorkflowInterface $workflow,
  array $context,
) {
  // Disable the check on "some_field".
  if ($context['entity']->bundle() == 'something') {
    $fields_to_check['some_field'] = FALSE;
  }
}

/**
 * Alter the fields that can be analyzed during classification.
 *
 * @param array $analyzable_fields
 *   Associative array of analyzable fields with field names as keys and labels
 *   as values.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 */
function hook_ocha_content_classification_analyzable_fields_alter(
  array &$analyzable_fields,
  ClassificationWorkflowInterface $workflow,
) {
  // Remove a field from the list of analyzable fields.
  unset($analyzable_fields['field_my_custom_text']);

  // Add another field as analyzable.
  $analyzable_fields['some_other_field'] = t('Some other field');
}

/**
 * Alter the fields that can be filled with classification results.
 *
 * @param array $fillable_fields
 *   Associative array of fillable fields with field names as keys and labels
 *   as values.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 */
function hook_ocha_content_classification_fillable_fields_alter(
  array &$fillable_fields,
  ClassificationWorkflowInterface $workflow,
) {
  // Remove a field from the list of fillable fields.
  unset($fillable_fields['field_my_custom_text']);

  // Add another field as fillable.
  $fillable_fields['some_other_field'] = t('Some other field');
}

/**
 * Alter the fields that should be forcibly updated during classification.
 *
 * @param array $force_update
 *   Associative array of fields to always update with field names as keys and
 *   TRUE or FALSE as values. Set to TRUE to force the update of the field and
 *   set to FALSE to skip if the field already has a value.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: The entity being classified.
 */
function hook_ocha_content_classification_force_field_update_alter(
  array &$force_update,
  ClassificationWorkflowInterface $workflow,
  array $context,
) {
  // Force the update of the field.
  $force_update['field_my_custom_text'] = TRUE;

  // Skip if the update of the field if not empty.
  $force_update['some_other_field'] = FALSE;
}

/**
 * Alter whether user permissions should be checked before classification.
 *
 * @param bool $check_permissions
 *   Whether to check user permissions. Set to FALSE to bypass permission
 *   checks.
 * @param \Drupal\Core\Session\AccountInterface $account
 *   The user account for which to check permissions.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: The entity being classified.
 */
function hook_ocha_content_classification_user_permission_check_alter(
  bool &$check_permissions,
  AccountInterface $account,
  array $context,
) {
  // Example: Bypass permission checks for a specific role.
  if ($account->hasRole('content_editor')) {
    $check_permissions = FALSE;
  }

  // Example: Bypass permission checks for a specific entity type.
  if ($context['entity']->getEntityTypeId() == 'taxonomy_term') {
    $check_permissions = FALSE;
  }
}
