-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS ventech_db;
USE ventech_db;

-- USERS TABLE
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20),
    location VARCHAR(100),
    client_name VARCHAR(100),
    client_email VARCHAR(100),
    client_phone VARCHAR(20),
    client_address TEXT,
    role ENUM('admin', 'guest', 'client') DEFAULT 'guest',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- SAMPLE USER
INSERT INTO users (username, email, password, contact_number, location, role)
VALUES (
    'kaylaok1',  -- Change this to a unique username
    'kaylatizon5@gmail.com',
    '$2y$10$4ULv/NJcXUyCZBkFQyDtr.0g6IxE5ZBlAi4pbxv2.67xdWamNEoqC',
    '09612345678',
    'Manila',
    'client'
);


-- VENUE TABLE
CREATE TABLE IF NOT EXISTS venue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    image_path VARCHAR(255),
    description TEXT,
    latitude DOUBLE NOT NULL DEFAULT 0,
    longitude DOUBLE NOT NULL DEFAULT 0,
    location VARCHAR(255),
    additional_info TEXT,
    virtual_tour_url VARCHAR(255) NULL,
    reviews INT DEFAULT 0 CHECK (reviews >= 0),
    num_persons VARCHAR (100),
    amenities TEXT,
    wifi ENUM('yes', 'no') DEFAULT 'no',
    parking ENUM('yes', 'no') DEFAULT 'no',
    status ENUM('open', 'closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_lat_long ON venue(latitude, longitude);
CREATE FULLTEXT INDEX idx_venue_search ON venue(title, description);

-- RESERVATIONS TABLE
CREATE TABLE `venue_reservations` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `venue_id` INT NOT NULL,                 -- Foreign key to your venue table
  `user_id` INT NULL,                    -- Foreign key to your users table (allow NULL for guest reservations?)
  `event_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `mobile_country_code` VARCHAR(10) NULL,
  `mobile_number` VARCHAR(20) NULL,
  `address` VARCHAR(255) NULL,
  `country` VARCHAR(100) NULL,
  `notes` TEXT NULL,
  `voucher_code` VARCHAR(50) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending', -- e.g., pending, confirmed, cancelled, completed
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Optional: Add foreign key constraints if you have 'venue' and 'users' tables
  -- CONSTRAINT `fk_reservation_venue` FOREIGN KEY (`venue_id`) REFERENCES `venue`(`id`) ON DELETE CASCADE,
  -- CONSTRAINT `fk_reservation_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL, -- Or CASCADE depending on logic

  -- Optional: Add indexes for faster lookups
  INDEX `idx_venue_id` (`venue_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_event_date` (`event_date`),
  INDEX `idx_email` (`email`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- UNAVAILABLE DATES
CREATE TABLE IF NOT EXISTS unavailable_dates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    unavailable_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Adding a timestamp to track when the date was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Automatically updates when modified
    FOREIGN KEY (venue_id) REFERENCES venue(id) ON DELETE CASCADE,
    UNIQUE (venue_id, unavailable_date) -- Ensures that the same venue cannot have multiple entries for the same date
) ENGINE=InnoDB;


-- VENUE IMAGES
CREATE TABLE IF NOT EXISTS venue_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venue_id) REFERENCES venue(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- VENUE DETAILS


-- MEDIA (IMAGE/VIDEO)
CREATE TABLE IF NOT EXISTS venue_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    media_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venue_id) REFERENCES venue(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- VENUE REVIEWS
CREATE TABLE IF NOT EXISTS venue_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venue_id) REFERENCES venue(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_reviews ON venue_reviews(venue_id, rating);

-- CLIENT INFO (For storing backup client profile optionally)
CREATE TABLE IF NOT EXISTS client_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT,
    client_name VARCHAR(100) NOT NULL,
    client_email VARCHAR(100) NOT NULL,
    client_phone VARCHAR(20),
    client_address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venue_id) REFERENCES venue(id) ON DELETE SET NULL
) ENGINE=InnoDB;


