<?php

namespace Drupal\geocoder;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\geocoder\Annotation\GeocoderDumper;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Provides a plugin manager for geocoder dumpers.
 */
class DumperPluginManager extends GeocoderPluginManagerBase {

  private $maxLengthFieldTypes = [
    "text",
    "string",
  ];

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct('Plugin/Geocoder/Dumper', $namespaces, $module_handler, DumperInterface::class, GeocoderDumper::class);
    $this->alterInfo('geocoder_dumper_info');
    $this->setCacheBackend($cache_backend, 'geocoder_dumper_plugins');
    $this->logger = $logger_factory;
  }

  /**
   * Define an Address field value from a Geojson string.
   *
   * @param string $geojson
   *   The GeoJson place string.
   *
   * @return array
   *   An array of the Address field value.
   */
  public function setAddressFieldFromGeojson($geojson) {
    $geojson_array = Json::decode($geojson);

    $address_field_value = [
      'country_code' => isset($geojson_array['properties']['countryCode']) ? substr($geojson_array['properties']['countryCode'], 0, 2) : '',
      'address_line1' => isset($geojson_array['properties']['streetName']) ? $geojson_array['properties']['streetName'] : '',
      'postal_code' => isset($geojson_array['properties']['postalCode']) ? $geojson_array['properties']['postalCode'] : '',
      'locality' => isset($geojson_array['properties']['locality']) ? $geojson_array['properties']['locality'] : '',
    ];

    return $address_field_value;

  }

  /**
   * Check|Fix some incompatibility between Dumper output and Field Config.
   *
   * @param string $dumper_result
   *   The Dumper result string.
   * @param \Drupal\geocoder\DumperInterface|\Drupal\Component\Plugin\PluginInspectionInterface $dumper
   *   The Dumper.
   * @param \Drupal\Core\Field\FieldConfigInterface $field_config
   *   The Field Configuration.
   */
  public function fixDumperFieldIncompatibility(&$dumper_result, $dumper, FieldConfigInterface $field_config) {
    // Fix not UTF-8 encoded result strings.
    // https://stackoverflow.com/questions/6723562/how-to-detect-malformed-utf-8-string-in-php
    if (!preg_match('//u', $dumper_result)) {
      $dumper_result = utf8_encode($dumper_result);
    }

    // If the field is a string|text type check if the result length is
    // compatible with its max_length definition, otherwise truncate it and
    // set | log a warning message.
    if (in_array($field_config->getType(), $this->maxLengthFieldTypes) &&
      strlen($dumper_result) > $field_config->getFieldStorageDefinition()->getSetting('max_length')) {

      $incompatibility_warning_message = t("The '@field_name' field 'max length' property is not compatible with the chosen '@dumper' dumper.<br>Thus <b>be aware</b> <u>the dumper output result has been truncated to @max_length chars (max length)</u>.<br> You are advised to change the '@field_name' field definition or chose another compatible dumper.", [
        '@field_name' => $field_config->getLabel(),
        '@dumper' => $dumper->getPluginId(),
        '@max_length' => $field_config->getFieldStorageDefinition()->getSetting('max_length'),
      ]);

      $dumper_result = substr($dumper_result, 0, $field_config->getFieldStorageDefinition()->getSetting('max_length'));

      // Display a max-length incompatibility warning message.
      drupal_set_message($incompatibility_warning_message, 'warning');

      // Log the max-length incompatibility.
      $this->logger->get('geocoder')->warning($incompatibility_warning_message);
    }
  }

}
