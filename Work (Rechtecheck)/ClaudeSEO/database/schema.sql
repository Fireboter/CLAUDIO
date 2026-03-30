CREATE TABLE IF NOT EXISTS rechtsgebiete (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    slug VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('draft','published','unpublished') DEFAULT 'draft',
    performance_score INT DEFAULT 0,
    avg_position FLOAT DEFAULT 0,
    total_clicks INT DEFAULT 0,
    total_impressions INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rechtsfragen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechtsgebiet_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    status ENUM('draft','published','unpublished') DEFAULT 'draft',
    performance_score INT DEFAULT 0,
    avg_position FLOAT DEFAULT 0,
    total_clicks INT DEFAULT 0,
    total_impressions INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rf_rg FOREIGN KEY (rechtsgebiet_id) REFERENCES rechtsgebiete(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS variation_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechtsgebiet_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vt_rg FOREIGN KEY (rechtsgebiet_id) REFERENCES rechtsgebiete(id) ON DELETE CASCADE,
    UNIQUE KEY uq_vt (rechtsgebiet_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS variation_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    variation_type_id INT NOT NULL,
    value VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    tier INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vv_vt FOREIGN KEY (variation_type_id) REFERENCES variation_types(id) ON DELETE CASCADE,
    UNIQUE KEY uq_vv (variation_type_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rechtsgebiet_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechtsgebiet_id INT NOT NULL UNIQUE,
    title VARCHAR(500),
    meta_description TEXT,
    meta_keywords TEXT,
    html_content LONGTEXT,
    og_title VARCHAR(500),
    og_description TEXT,
    generation_status ENUM('pending','generating','generated','failed','published') DEFAULT 'pending',
    generated_by VARCHAR(50),
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rgp_rg FOREIGN KEY (rechtsgebiet_id) REFERENCES rechtsgebiete(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rechtsfrage_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechtsfrage_id INT NOT NULL UNIQUE,
    title VARCHAR(500),
    meta_description TEXT,
    meta_keywords TEXT,
    html_content LONGTEXT,
    og_title VARCHAR(500),
    og_description TEXT,
    generation_status ENUM('pending','generating','generated','failed','published') DEFAULT 'pending',
    generated_by VARCHAR(50),
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rfp_rf FOREIGN KEY (rechtsfrage_id) REFERENCES rechtsfragen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS variation_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechtsfrage_id INT NOT NULL,
    variation_value_id INT NOT NULL,
    title VARCHAR(500),
    meta_description TEXT,
    meta_keywords TEXT,
    html_content LONGTEXT,
    og_title VARCHAR(500),
    og_description TEXT,
    generation_status ENUM('pending','generating','generated','failed','published') DEFAULT 'pending',
    generated_by VARCHAR(50),
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vp_rf FOREIGN KEY (rechtsfrage_id) REFERENCES rechtsfragen(id) ON DELETE CASCADE,
    CONSTRAINT fk_vp_vv FOREIGN KEY (variation_value_id) REFERENCES variation_values(id) ON DELETE CASCADE,
    UNIQUE KEY uq_vp (rechtsfrage_id, variation_value_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS page_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_type ENUM('rechtsgebiet','rechtsfrage','variation') NOT NULL,
    page_id INT NOT NULL,
    url VARCHAR(500),
    clicks INT DEFAULT 0,
    impressions INT DEFAULT 0,
    ctr FLOAT DEFAULT 0,
    avg_position FLOAT DEFAULT 0,
    date DATE NOT NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pa_lookup (page_type, page_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS page_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_type ENUM('rechtsgebiet','rechtsfrage','variation') NOT NULL,
    page_id INT NOT NULL,
    action ENUM('keep','update','delete','create','expand') NOT NULL,
    reason TEXT,
    priority_score FLOAT DEFAULT 0,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    executed_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    api_name VARCHAR(50) NOT NULL,
    calls_count INT DEFAULT 0,
    tokens_used INT DEFAULT 0,
    cost_cents INT DEFAULT 0,
    UNIQUE KEY uq_au (date, api_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cron_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phase VARCHAR(50) NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    items_processed INT DEFAULT 0,
    errors INT DEFAULT 0,
    notes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
