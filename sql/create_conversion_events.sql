-- Table: conversion_events
CREATE TABLE IF NOT EXISTS `conversion_events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `event_name` VARCHAR(191) NOT NULL,
  `event_label` VARCHAR(255) DEFAULT NULL,
  `page` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_event_name` (`event_name`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
