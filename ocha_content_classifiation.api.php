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
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface;

/**
 * Alter the list of fields populated by the classifier.
 *
 * @param array $classified_fields
 *   The list of fields that the classifier handled, keyed by type:
 *   classifiable or fillable with the list of fields keyed by field names
 *   as values.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow used for classification.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: the entity being classified
 *   - classifier: the classifier plugin
 *   - data: the raw data used by the classifier (depends on the classifier).
 */
function hook_ocha_content_classification_classified_fields_alter(
  array &$classified_fields,
  ClassificationWorkflowInterface $workflow,
  array $context,
) {
  $entity = $context['entity'];
  if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'something') {
    // Do not update the body field for this type of node.
    unset($classified_fields['fillable']['body']);
  }
}

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
 * @return array
 *   List of entity fields modified in the hook.
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
 * Alter the skip classification flag.
 *
 * This allows to bypass the check on the emptiness of a classifiable or
 * fillable field when determining if the classification should proceed.
 *
 * @param bool $skip_classification
 *   Flag to indicate whether the classification should be skipped or not.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: The entity being classified.
 */
function hook_ocha_content_classification_skip_classification_alter(
  bool &$skip_classification,
  ClassificationWorkflowInterface $workflow,
  array $context,
) {
  // Disable the check on "some_field".
  if ($context['entity']->bundle() == 'something') {
    $skip_classification = TRUE;
  }
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
 *   - workflow: The classification workflow.
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

/**
 * Allow other module to act before the ocha content classification module.
 *
 * Called at the beginning of the ocha_content_classification_entity_presave().
 * This can be used to force the requeueing of the entity for example.
 */
function hook_ocha_content_classification_pre_entity_presave(EntityInterface $entity) {
  if (!$entity->field_something->equals($entity->original->field_something)) {
    \Drupal::service('ocha_content_classification.content_entity_classifier')
      ->requeueEntity($entity);
  }
}

/**
 * Allow other module to act after the ocha content classification module.
 *
 * This is called at the end of the ocha_content_classification_entity_presave()
 * which may have added a `ocha_content_classification_status` flag on the
 * entity that can be used to determine if the classification is still pending,
 * has failed or has completed.
 */
function hook_ocha_content_classification_post_entity_presave(EntityInterface $entity) {
  if (isset($entity->ocha_content_classification_status) && $entity->ocha_content_classification_status === ClassificationStatus::Failed) {
    $entity->setRevisionLogMessage('Oh no!');
  }
}

/**
 * Modify the entity being prepare for the classification.
 *
 * This can be used to modify the fields to analyze for the classification.
 *
 * @param \Drupal\Core\Entity\EntityInterface $prepared_entity
 *   Entity being prepare for classification. This is a clone of the entity
 *   being classified and can be safely modified.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: The original entity being classified
 *   - classifier: The classifier plugin.
 */
function hook_ocha_content_classification_prepare_entity_alter(
  EntityInterface &$prepared_entity,
  ClassificationWorkflowInterface $workflow,
  array $context,
) {
  $context['entity']->get('somefield')->filter(function ($item) {
    return $item->value !== 'something that cannot be analyzed';
  });
}

/**
 * Perform extra validation on the entity being classified.
 *
 * This allows to perform additional validation of the entity before performing
 * the actual classification. If the entity data is considered invalid, then the
 * classification will be marked has failed.
 *
 * @param bool $invalid
 *   Flag to indicate whether the entity is valid or not for the classification.
 * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
 *   The workflow being used for classification.
 * @param array $context
 *   An array containing contextual information:
 *   - entity: The entity being classified
 *   - classifier: The classifier plugin.
 */
function hook_ocha_content_classification_validate_entity_data_alter(
  bool &$invalid,
  ClassificationWorkflowInterface $workflow,
  array $context,
) {
  if ($context['entity']->get('somefield')->value === 'some invalid value') {
    $invalid = TRUE;
  }
}
