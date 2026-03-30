<?php
/**
 * Database setup script — run once, then DELETE this file.
 * Access: https://bawywear.com/setup.php
 * Delete after use!
 */
require_once __DIR__ . '/config.php';
require_once SHARED_PATH . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db_connect();

$tables = [

'CollectionGroup' => "CREATE TABLE IF NOT EXISTS `CollectionGroup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'Collection' => "CREATE TABLE IF NOT EXISTS `Collection` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `image` text,
  `groupId` int DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`groupId`) REFERENCES `CollectionGroup`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'Product' => "CREATE TABLE IF NOT EXISTS `Product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `price` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT NULL,
  `images` text DEFAULT NULL,
  `collectionId` int DEFAULT NULL,
  `onDemand` tinyint(1) NOT NULL DEFAULT '0',
  `isPreorder` tinyint(1) NOT NULL DEFAULT '0',
  `hasSize` tinyint(1) NOT NULL DEFAULT '0',
  `hasColor` tinyint(1) NOT NULL DEFAULT '0',
  `hasMaterial` tinyint(1) NOT NULL DEFAULT '0',
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`collectionId`) REFERENCES `Collection`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'ProductVariant' => "CREATE TABLE IF NOT EXISTS `ProductVariant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productId` int NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `material` varchar(100) DEFAULT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `reserved` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`productId`) REFERENCES `Product`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'ProductImage' => "CREATE TABLE IF NOT EXISTS `ProductImage` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productId` int NOT NULL,
  `url` text NOT NULL,
  `displayOrder` int NOT NULL DEFAULT '0',
  `color` varchar(100) DEFAULT NULL,
  `material` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`productId`) REFERENCES `Product`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'ProductNovedades' => "CREATE TABLE IF NOT EXISTS `ProductNovedades` (
  `productId` int NOT NULL,
  `addedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`productId`),
  FOREIGN KEY (`productId`) REFERENCES `Product`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'Order' => "CREATE TABLE IF NOT EXISTS `Order` (
  `id` int NOT NULL AUTO_INCREMENT,
  `orderNumber` varchar(20) NOT NULL,
  `customerName` varchar(255) NOT NULL,
  `customerEmail` varchar(255) NOT NULL,
  `customerPhone` varchar(50) DEFAULT NULL,
  `shippingAddress` text,
  `totalAmount` decimal(10,2) NOT NULL,
  `shippingMethod` varchar(50) NOT NULL DEFAULT 'pickup',
  `shippingCost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `sessionId` varchar(100) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`orderNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'OrderItem' => "CREATE TABLE IF NOT EXISTS `OrderItem` (
  `id` int NOT NULL AUTO_INCREMENT,
  `orderId` int NOT NULL,
  `productId` int NOT NULL,
  `variantId` int DEFAULT NULL,
  `quantity` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `isPreorder` tinyint(1) NOT NULL DEFAULT '0',
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`orderId`) REFERENCES `Order`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'CartReservation' => "CREATE TABLE IF NOT EXISTS `CartReservation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sessionId` varchar(100) NOT NULL,
  `productVariantId` int NOT NULL,
  `quantity` int NOT NULL,
  `expiresAt` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`sessionId`),
  KEY (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'DiscountCode' => "CREATE TABLE IF NOT EXISTS `DiscountCode` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `orderId` int DEFAULT NULL,
  `productId` int DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expiresAt` datetime NOT NULL,
  `customerEmail` varchar(255) DEFAULT NULL,
  `usedAt` datetime DEFAULT NULL,
  `usedInOrderId` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'NewsletterSubscriber' => "CREATE TABLE IF NOT EXISTS `NewsletterSubscriber` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT '1',
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'ContactSubmission' => "CREATE TABLE IF NOT EXISTS `ContactSubmission` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `formType` varchar(50) NOT NULL DEFAULT 'contact',
  `isRead` tinyint(1) NOT NULL DEFAULT '0',
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'SiteSettings' => "CREATE TABLE IF NOT EXISTS `SiteSettings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

$ok = 0; $fail = 0;
foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $name\n";
        $ok++;
    } catch (PDOException $e) {
        echo "FAIL: $name — " . $e->getMessage() . "\n";
        $fail++;
    }
}

// Default SiteSettings
$defaults = [
    'hero_title'                       => 'Nueva colección',
    'hero_subtitle'                    => 'Descubre las últimas novedades',
    'hero_button_text'                 => 'Ver colección',
    'hero_button_link'                 => '/shop',
    'hero_image'                       => '',
    'shipping_spain_price'             => '7.50',
    'shipping_spain_free_threshold'    => '80',
    'shipping_europe_price'            => '12.00',
    'shipping_europe_discounted_price' => '4.50',
    'shipping_europe_discount_threshold' => '80',
    'tax_percentage'                   => '4.5',
];

echo "\nInserting default SiteSettings:\n";
foreach ($defaults as $key => $value) {
    try {
        $pdo->prepare('INSERT IGNORE INTO SiteSettings (`site`, `key`, `value`) VALUES (?, ?, ?)')->execute([SITE_ID, $key, $value]);
        echo "OK: $key\n";
    } catch (PDOException $e) {
        echo "FAIL: $key — " . $e->getMessage() . "\n";
    }
}

// Create uploads/products directory
$uploadsProducts = UPLOADS_PATH . '/products';
if (!is_dir($uploadsProducts)) {
    mkdir($uploadsProducts, 0755, true);
    echo "\nCreated: uploads/products/\n";
} else {
    echo "\nExists: uploads/products/\n";
}

echo "\nDone. Tables OK: $ok, Failed: $fail\n";
echo "DELETE THIS FILE after setup is complete!\n";
