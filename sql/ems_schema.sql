-- =====================================================
-- WorkNest - Employee Management System Database
-- Database Name: worknest
-- Version: 1.0
-- Created: March 2026
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- =====================================================
-- Create Database
-- =====================================================
DROP DATABASE IF EXISTS `worknest`;
CREATE DATABASE IF NOT EXISTS `worknest` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `worknest`;

-- =====================================================
-- Table: users
-- Description: Stores all users (employees and admins)
-- =====================================================
CREATE TABLE `users` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    `department` VARCHAR(100) DEFAULT NULL,
    `position` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `phone` VARCHAR(20) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `profile_image` VARCHAR(255) DEFAULT NULL,
    `date_of_joining` DATE DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`),
    INDEX `idx_users_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: attendance
-- Description: Daily attendance records for employees
-- =====================================================
CREATE TABLE `attendance` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) UNSIGNED NOT NULL,
    `attendance_date` DATE NOT NULL,
    `check_in` TIME DEFAULT NULL,
    `check_out` TIME DEFAULT NULL,
    `status` ENUM('present', 'absent', 'late', 'early', 'halfday') NOT NULL DEFAULT 'present',
    `work_hours` DECIMAL(4,2) GENERATED ALWAYS AS (
        CASE 
            WHEN `check_in` IS NOT NULL AND `check_out` IS NOT NULL 
            THEN TIME_TO_SEC(TIMEDIFF(`check_out`, `check_in`)) / 3600 
            ELSE NULL 
        END
    ) STORED,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_attendance` (`user_id`, `attendance_date`),
    INDEX `idx_attendance_date` (`attendance_date`),
    INDEX `idx_attendance_status` (`status`),
    INDEX `idx_attendance_user_date` (`user_id`, `attendance_date`),
    CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: leaves
-- Description: Leave requests submitted by employees
-- =====================================================
CREATE TABLE `leaves` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) UNSIGNED NOT NULL,
    `leave_type` ENUM('casual_leave', 'sick_leave', 'paid_leave', 'unpaid_leave') NOT NULL DEFAULT 'casual_leave',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `total_days` INT(11) GENERATED ALWAYS AS (DATEDIFF(`end_date`, `start_date`) + 1) STORED,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `admin_remarks` TEXT DEFAULT NULL,
    `approved_by` INT(11) UNSIGNED DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_leaves_user` (`user_id`),
    INDEX `idx_leaves_status` (`status`),
    INDEX `idx_leaves_dates` (`start_date`, `end_date`),
    INDEX `idx_leaves_type` (`leave_type`),
    INDEX `idx_leaves_created` (`created_at`),
    CONSTRAINT `fk_leaves_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_leaves_approved_by` FOREIGN KEY (`approved_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: notices
-- Description: Announcements and notices from admin
-- =====================================================
CREATE TABLE `notices` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT(11) UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_notices_active` (`is_active`),
    INDEX `idx_notices_priority` (`priority`),
    INDEX `idx_notices_created` (`created_at`),
    CONSTRAINT `fk_notices_created_by` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: late_conversion_tracker
-- Description: Tracks late attendance for 3-late = 1-absent rule
-- =====================================================
CREATE TABLE `late_conversion_tracker` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) UNSIGNED NOT NULL,
    `pending_late_count` INT(11) NOT NULL DEFAULT 0,
    `total_conversions` INT(11) NOT NULL DEFAULT 0 COMMENT 'Total times 3 lates converted to 1 absent',
    `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_user` (`user_id`),
    CONSTRAINT `fk_late_tracker_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: leave_balances
-- Description: Yearly leave balance for each employee
-- =====================================================
CREATE TABLE `leave_balances` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) UNSIGNED NOT NULL,
    `year` YEAR NOT NULL,
    `casual_leave` INT(11) NOT NULL DEFAULT 2,
    `sick_leave` INT(11) NOT NULL DEFAULT 3,
    `paid_leave` INT(11) NOT NULL DEFAULT 2,
    `casual_leave_used` INT(11) NOT NULL DEFAULT 0,
    `sick_leave_used` INT(11) NOT NULL DEFAULT 0,
    `paid_leave_used` INT(11) NOT NULL DEFAULT 0,
    `unpaid_leave_used` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_user_year` (`user_id`, `year`),
    CONSTRAINT `fk_leave_balance_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: departments
-- Description: Department master list
-- =====================================================
CREATE TABLE `departments` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `head_id` INT(11) UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_dept_name` (`name`),
    INDEX `idx_dept_active` (`is_active`),
    CONSTRAINT `fk_dept_head` FOREIGN KEY (`head_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: positions
-- Description: Job position/designation master list
-- =====================================================
CREATE TABLE `positions` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(100) NOT NULL,
    `department_id` INT(11) UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_position_dept` (`department_id`),
    INDEX `idx_position_active` (`is_active`),
    CONSTRAINT `fk_position_dept` FOREIGN KEY (`department_id`) 
        REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: holidays
-- Description: Official holidays calendar
-- =====================================================
CREATE TABLE `holidays` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `date` DATE NOT NULL,
    `type` ENUM('public', 'optional', 'restricted') NOT NULL DEFAULT 'public',
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_holiday_date` (`date`),
    INDEX `idx_holiday_date` (`date`),
    INDEX `idx_holiday_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: settings
-- Description: System configuration settings
-- =====================================================
CREATE TABLE `settings` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: activity_logs
-- Description: Audit trail for important actions
-- =====================================================
CREATE TABLE `activity_logs` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., user, leave, attendance',
    `entity_id` INT(11) UNSIGNED DEFAULT NULL,
    `old_values` JSON DEFAULT NULL,
    `new_values` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_log_user` (`user_id`),
    INDEX `idx_log_action` (`action`),
    INDEX `idx_log_entity` (`entity_type`, `entity_id`),
    INDEX `idx_log_created` (`created_at`),
    CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Insert Default Data
-- =====================================================

-- Default Admin User (Password: Admin@123)
INSERT INTO `users` (`username`, `email`, `password`, `role`, `department`, `position`, `status`) VALUES
('Admin', 'admin@worknest.com', 'Admin@123', 'admin', 'Administration', 'System Administrator', 'active');

-- Sample Employees (Password: Employee@123)
INSERT INTO `users` (`username`, `email`, `password`, `role`, `department`, `position`, `status`, `date_of_joining`) VALUES
('John Doe', 'john@worknest.com', 'Employee@123', 'employee', 'Engineering', 'Software Developer', 'active', '2025-01-15'),
('Jane Smith', 'jane@worknest.com', 'Employee@123', 'employee', 'Marketing', 'Marketing Manager', 'active', '2025-02-01'),
('Bob Wilson', 'bob@worknest.com', 'Employee@123', 'employee', 'Engineering', 'Senior Developer', 'active', '2024-11-20'),
('Alice Brown', 'alice@worknest.com', 'Employee@123', 'employee', 'HR', 'HR Executive', 'active', '2025-03-01');

-- Default Departments
INSERT INTO `departments` (`name`, `description`) VALUES
('Administration', 'Administrative and management functions'),
('Engineering', 'Software development and technical operations'),
('Marketing', 'Marketing and brand management'),
('HR', 'Human Resources and employee relations'),
('Finance', 'Financial planning and accounting'),
('Operations', 'Business operations and logistics');

-- Default Positions
INSERT INTO `positions` (`title`, `department_id`, `description`) VALUES
('System Administrator', 1, 'Manages system and IT infrastructure'),
('Software Developer', 2, 'Develops and maintains software applications'),
('Senior Developer', 2, 'Lead developer with advanced responsibilities'),
('Marketing Manager', 3, 'Manages marketing campaigns and strategies'),
('HR Executive', 4, 'Handles recruitment and employee relations'),
('Accountant', 5, 'Manages financial records and transactions'),
('Operations Manager', 6, 'Oversees daily business operations');

-- Default System Settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('office_start_time', '09:15:00', 'string', 'Office start time (late if check-in after this)'),
('attendance_cutoff_time', '10:30:00', 'string', 'Cutoff time (absent if check-in after this)'),
('attendance_threshold', '75', 'number', 'Minimum attendance percentage for leave eligibility'),
('late_to_absent_count', '3', 'number', 'Number of late days that convert to 1 absent'),
('max_auto_approvals', '2', 'number', 'Maximum leave requests auto-approved per batch'),
('company_name', 'WorkNest', 'string', 'Company name displayed in system'),
('fiscal_year_start', '01-01', 'string', 'Fiscal year start date (MM-DD)');

-- Default Holidays for 2026
INSERT INTO `holidays` (`name`, `date`, `type`, `description`) VALUES
('New Year', '2026-01-01', 'public', 'New Year Day'),
('Republic Day', '2026-01-26', 'public', 'Republic Day of India'),
('Holi', '2026-03-10', 'public', 'Festival of Colors'),
('Good Friday', '2026-04-03', 'public', 'Good Friday'),
('Independence Day', '2026-08-15', 'public', 'Independence Day of India'),
('Gandhi Jayanti', '2026-10-02', 'public', 'Birth anniversary of Mahatma Gandhi'),
('Diwali', '2026-11-08', 'public', 'Festival of Lights'),
('Christmas', '2026-12-25', 'public', 'Christmas Day');

-- Initialize Leave Balances for existing employees
INSERT INTO `leave_balances` (`user_id`, `year`, `casual_leave`, `sick_leave`, `paid_leave`)
SELECT `id`, YEAR(CURDATE()), 2, 3, 2 
FROM `users` 
WHERE `role` = 'employee';

-- Sample Welcome Notice
INSERT INTO `notices` (`title`, `description`, `priority`, `created_by`) VALUES
('Welcome to WorkNest!', 'Welcome to the WorkNest Employee Management System. Please check in daily and submit leave requests in advance.', 'high', 1);

-- =====================================================
-- Views for Common Queries
-- =====================================================

-- View: Employee Attendance Summary
CREATE OR REPLACE VIEW `v_attendance_summary` AS
SELECT 
    u.id AS user_id,
    u.username,
    u.department,
    u.position,
    COUNT(a.id) AS total_days,
    SUM(CASE WHEN a.status IN ('present', 'early') THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN a.status = 'halfday' THEN 1 ELSE 0 END) AS halfday_days,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
    ROUND(
        (SUM(CASE WHEN a.status IN ('present', 'late', 'early', 'halfday') THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 
        2
    ) AS attendance_percentage
FROM `users` u
LEFT JOIN `attendance` a ON u.id = a.user_id
WHERE u.role = 'employee'
GROUP BY u.id, u.username, u.department, u.position;

-- View: Employee Leave Summary
CREATE OR REPLACE VIEW `v_leave_summary` AS
SELECT 
    u.id AS user_id,
    u.username,
    lb.year,
    lb.casual_leave AS casual_total,
    lb.casual_leave_used,
    (lb.casual_leave - lb.casual_leave_used) AS casual_remaining,
    lb.sick_leave AS sick_total,
    lb.sick_leave_used,
    (lb.sick_leave - lb.sick_leave_used) AS sick_remaining,
    lb.paid_leave AS paid_total,
    lb.paid_leave_used,
    (lb.paid_leave - lb.paid_leave_used) AS paid_remaining,
    lb.unpaid_leave_used
FROM `users` u
JOIN `leave_balances` lb ON u.id = lb.user_id
WHERE u.role = 'employee';

-- View: Pending Leave Requests with Attendance
CREATE OR REPLACE VIEW `v_pending_leaves` AS
SELECT 
    l.id,
    l.user_id,
    l.leave_type,
    l.start_date,
    l.end_date,
    l.total_days AS leave_days,
    l.reason,
    l.status,
    l.admin_remarks,
    l.approved_by,
    l.approved_at,
    l.created_at,
    l.updated_at,
    u.username,
    u.email,
    u.department,
    vas.attendance_percentage,
    vas.present_days,
    vas.total_days AS attendance_total_days
FROM `leaves` l
JOIN `users` u ON l.user_id = u.id
LEFT JOIN `v_attendance_summary` vas ON u.id = vas.user_id
WHERE l.status = 'pending'
ORDER BY vas.attendance_percentage DESC, l.created_at ASC;

-- =====================================================
-- Triggers
-- =====================================================

-- Trigger: Update leave balance when leave is approved
DELIMITER //
CREATE TRIGGER `after_leave_approved` 
AFTER UPDATE ON `leaves`
FOR EACH ROW
BEGIN
    IF NEW.status = 'approved' AND OLD.status = 'pending' THEN
        UPDATE `leave_balances` 
        SET 
            `casual_leave_used` = `casual_leave_used` + IF(NEW.leave_type = 'casual_leave', NEW.total_days, 0),
            `sick_leave_used` = `sick_leave_used` + IF(NEW.leave_type = 'sick_leave', NEW.total_days, 0),
            `paid_leave_used` = `paid_leave_used` + IF(NEW.leave_type = 'paid_leave', NEW.total_days, 0),
            `unpaid_leave_used` = `unpaid_leave_used` + IF(NEW.leave_type = 'unpaid_leave', NEW.total_days, 0)
        WHERE `user_id` = NEW.user_id AND `year` = YEAR(NEW.start_date);
    END IF;
END//
DELIMITER ;

-- Trigger: Create leave balance for new employee
DELIMITER //
CREATE TRIGGER `after_employee_insert`
AFTER INSERT ON `users`
FOR EACH ROW
BEGIN
    IF NEW.role = 'employee' THEN
        INSERT INTO `leave_balances` (`user_id`, `year`, `casual_leave`, `sick_leave`, `paid_leave`)
        VALUES (NEW.id, YEAR(CURDATE()), 2, 3, 2);
    END IF;
END//
DELIMITER ;

-- =====================================================
-- Stored Procedures
-- =====================================================

-- Procedure: Get Employee Dashboard Stats
DELIMITER //
CREATE PROCEDURE `sp_get_employee_stats`(IN p_user_id INT)
BEGIN
    -- Attendance Stats
    SELECT 
        COUNT(*) AS total_days,
        SUM(CASE WHEN status IN ('present', 'late', 'early', 'halfday') THEN 1 ELSE 0 END) AS present_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late_days,
        SUM(CASE WHEN status = 'halfday' THEN 1 ELSE 0 END) AS halfday_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
        ROUND(
            (SUM(CASE WHEN status IN ('present', 'late', 'early', 'halfday') THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 
            2
        ) AS attendance_percentage
    FROM `attendance`
    WHERE user_id = p_user_id;
    
    -- Leave Balance
    SELECT * FROM `leave_balances` 
    WHERE user_id = p_user_id AND year = YEAR(CURDATE());
    
    -- Pending Leave Requests
    SELECT * FROM `leaves` 
    WHERE user_id = p_user_id AND status = 'pending';
END//
DELIMITER ;

-- Procedure: Auto-process leave requests
DELIMITER //
CREATE PROCEDURE `sp_auto_process_leaves`(IN p_max_approvals INT, IN p_threshold DECIMAL(5,2))
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_leave_id INT;
    DECLARE v_user_id INT;
    DECLARE v_leave_type VARCHAR(20);
    DECLARE v_attendance_pct DECIMAL(5,2);
    DECLARE v_approved_count INT DEFAULT 0;
    
    DECLARE cur CURSOR FOR 
        SELECT l.id, l.user_id, l.leave_type, COALESCE(vas.attendance_percentage, 100) AS att_pct
        FROM leaves l
        LEFT JOIN v_attendance_summary vas ON l.user_id = vas.user_id
        WHERE l.status = 'pending'
        ORDER BY att_pct DESC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_leave_id, v_user_id, v_leave_type, v_attendance_pct;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Check if max approvals reached
        IF v_approved_count >= p_max_approvals THEN
            UPDATE leaves SET status = 'rejected' WHERE id = v_leave_id;
        -- Check attendance threshold (sick leave exempt)
        ELSEIF v_attendance_pct < p_threshold AND v_leave_type != 'sick_leave' THEN
            UPDATE leaves SET status = 'rejected' WHERE id = v_leave_id;
        ELSE
            UPDATE leaves SET status = 'approved', approved_at = NOW() WHERE id = v_leave_id;
            SET v_approved_count = v_approved_count + 1;
        END IF;
    END LOOP;
    
    CLOSE cur;
    
    SELECT v_approved_count AS approved_count;
END//
DELIMITER ;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;