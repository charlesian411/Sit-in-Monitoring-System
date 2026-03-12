CREATE DATABASE IF NOT EXISTS ccs_monitoring;
USE ccs_monitoring;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(20) NOT NULL UNIQUE,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    course VARCHAR(10) NOT NULL,
    course_level INT NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    address VARCHAR(255),
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
