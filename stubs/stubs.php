<?php

/**
 * @file
 * Drupal interface stubs for use when Drupal core is not available.
 *
 * All interfaces are defined in a single file to eliminate the need for a
 * separate loading manifest. Interfaces are ordered by dependency (parents
 * before children).
 */

declare(strict_types=1);

// Base entity interface.
namespace Drupal\Core\Entity;

interface EntityInterface {

  public function uuid();

  public function id();

  public function getEntityTypeId();

  public function bundle();

  public function label();

  public function toUrl($rel = NULL, array $options = []);

  public function save();

  public function delete();

}

// Base field interfaces.
namespace Drupal\Core\Field;

interface FieldItemInterface {

  public function __get($property_name);

  public function __set($property_name, $value);

  public function getValue();

  public function setValue($values, $notify = TRUE);

  public function isEmpty();

}

interface FieldItemListInterface extends \IteratorAggregate, \Countable {

  public function first();

  public function isEmpty();

  public function getValue();

  public function get($delta);

  public function __get($property_name);

  public function __set($property_name, $value);

  public function setValue($values, $notify = TRUE);

}

interface EntityReferenceFieldItemListInterface extends FieldItemListInterface {

  public function referencedEntities();

}

// Interfaces extending EntityInterface.
namespace Drupal\Core\Entity;

interface FieldableEntityInterface extends EntityInterface, \Traversable {

  public function hasField($field_name);

  public function get($field_name);

  public function set($field_name, $value, $notify = TRUE);

}

interface EntityChangedInterface extends EntityInterface {

  public function getChangedTime();

  public function setChangedTime($timestamp);

}

interface EntityPublishedInterface extends EntityInterface {

  public function isPublished();

}

interface ContentEntityInterface extends FieldableEntityInterface {
}

// Config entity interface.
namespace Drupal\Core\Config\Entity;

use Drupal\Core\Entity\EntityInterface;

interface ConfigEntityInterface extends EntityInterface {

  public function status();

}

// User module interface.
namespace Drupal\user;

use Drupal\Core\Entity\EntityInterface;

interface EntityOwnerInterface extends EntityInterface {

  public function getOwnerId();

}

// Node module interface.
namespace Drupal\node;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

interface NodeInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface {

  public function getTitle();

  public function getCreatedTime();

  public function isPromoted();

  public function isSticky();

}

// URL classes.
namespace Drupal\Core;

class Url {
  public function toString($collect_bubbleable_metadata = FALSE) {
    return '';
  }
}

class GeneratedUrl {
  public function getGeneratedUrl() {
    return '';
  }
}
