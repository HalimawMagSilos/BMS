DROP DATABASE IF EXISTS barangay_management;
CREATE DATABASE barangay_management;
USE barangay_management;

-- ===========================
-- USERS TABLE (User  Accounts)
-- ===========================
CREATE TABLE Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('resident', 'admin', 'barangay_official') NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE INDEX idx_users_email ON Users(email);

-- ===================================
-- PAYMENT PROCESS TABLE
-- ===================================
CREATE TABLE Payment_Process (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_number VARCHAR(50) NOT NULL,
    purpose ENUM('barangay_clearance', 'business_permit') NOT NULL,
    amount_fee DECIMAL(10, 2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE INDEX idx_payment_reference ON Payment_Process(reference_number);



-- ======================================
-- PROFILES TABLE (User  Personal Details)
-- ======================================
CREATE TABLE Profiles (
    profile_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(10) NULL,
    address TEXT NOT NULL,
    contact_number VARCHAR(15) NOT NULL,
    profile_picture TEXT NULL, -- Path to profile image
    birthday DATE NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);
CREATE INDEX idx_profiles_user_id ON Profiles(user_id);

-- ===================================
-- ID TYPES TABLE
-- ===================================
CREATE TABLE ID_Types (
    id_type_id INT AUTO_INCREMENT PRIMARY KEY,
    id_type_name VARCHAR(255) UNIQUE NOT NULL
);

-- Insert ID types
INSERT INTO ID_Types (id_type_name) VALUES
('PhilSys National ID (PhilID)'),
('Philippine Passport'),
('Driver’s License (LTO)'),
('UMID (Unified Multi-Purpose ID)'),
('Voter’s ID or Voter’s Certification (COMELEC)'),
('Postal ID'),
('School ID (with current registration form or proof of enrollment)'),
('Barangay ID or Certificate of Residency'),
('TIN ID (BIR - Bureau of Internal Revenue)'),
('NBI Clearance'),
('Philhealth ID');

-- ===================================
-- USER ID DOCUMENTS TABLE
-- ===================================
CREATE TABLE User_ID_Documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    id_type_id INT NOT NULL,
    document_file VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (id_type_id) REFERENCES ID_Types(id_type_id) ON DELETE CASCADE
);
CREATE INDEX idx_user_id_documents ON User_ID_Documents(user_id);

-- ============================
-- DEPARTMENTS TABLE
-- ============================
CREATE TABLE Departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(255) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO Departments(department_name) VALUES('Barangay Clearance'), ('Business Permit'), ('Financial Assistance');

-- ============================
-- BARANGAY OFFICIALS TABLE
-- ============================
CREATE TABLE Barangay_Officials (
    official_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    department_id INT NOT NULL,
    position VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES Departments(department_id) ON DELETE CASCADE
);
CREATE INDEX idx_barangay_officials_user ON Barangay_Officials(user_id);
CREATE INDEX idx_barangay_officials_department ON Barangay_Officials(department_id);

-- ===================================
-- BARANGAY CLEARANCE APPLICATIONS
-- ===================================

CREATE TABLE Barangay_Clearance_Applications (
    application_id VARCHAR(50) PRIMARY KEY,  -- Change to VARCHAR to accommodate formatted IDs
    user_id INT NOT NULL,
    id_document INT NOT NULL, 
    purpose TEXT NOT NULL,
    reference_number VARCHAR(50) UNIQUE NULL, 
    status ENUM('pending', 'approved') DEFAULT 'pending',
    downloadable_file TEXT NULL,  
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE INDEX idx_barangay_clearance_user ON Barangay_Clearance_Applications(user_id);

-- ===================================
-- BUSINESS PERMIT APPLICATIONS
-- ===================================

CREATE TABLE Business_Permit_Applications (
    application_id VARCHAR(50) PRIMARY KEY,  -- Change to VARCHAR to accommodate formatted IDs
    user_id INT NOT NULL,
    business_name VARCHAR(255) NOT NULL,
    business_type VARCHAR(100) NOT NULL,
    reference_number VARCHAR(50) UNIQUE NULL, 
    completed_application_form TEXT NOT NULL,
    valid_government_id INT NOT NULL,
    dti_certificate TEXT NULL,
    sec_registration TEXT NULL,
    lease_contract_or_tax_declaration TEXT NOT NULL,
    community_tax_certificate TEXT NOT NULL,
    previous_barangay_clearance TEXT NULL,
    status ENUM('pending', 'approved') DEFAULT 'pending',
    downloadable_file TEXT NULL, 
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE INDEX idx_business_permit_user ON Business_Permit_Applications(user_id);

-- ===================================
-- FINANCIAL ASSISTANCE APPLICATIONS
-- ===================================

CREATE TABLE Financial_Assistance_Applications(
    application_id VARCHAR(255) PRIMARY KEY,
    user_id INT NOT NULL,
    valid_government_id INT NOT NULL,
    barangay_clearance_or_residency VARCHAR(255) NOT NULL, -- Changed to VARCHAR
    proof_of_income VARCHAR(255) NOT NULL, -- Changed to VARCHAR
    medical_certificate VARCHAR(255) NULL, -- Changed to VARCHAR
    hospital_bills VARCHAR(255) NULL, -- Changed to VARCHAR
    prescriptions VARCHAR(255) NULL, -- Changed to VARCHAR
    senior_citizen_id VARCHAR(255) NULL, -- Changed to VARCHAR
    osca_certification VARCHAR(255) NULL, -- Changed to VARCHAR
    pwd_id VARCHAR(255) NULL, -- Changed to VARCHAR
    disability_certificate VARCHAR(255) NULL, -- Changed to VARCHAR
    reason TEXT NOT NULL,
    downloadable_file TEXT NULL, 
    status ENUM('pending', 'approved') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE INDEX idx_financial_assistance_user ON Financial_Assistance_Applications(user_id);





-- ===================================
-- NOTES TABLE (Staff Notes on Apps)
-- ===================================
CREATE TABLE Notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id VARCHAR(50) NOT NULL,  -- Change to VARCHAR to accommodate formatted IDs
    staff_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_notes_application ON Notes(application_id);

-- ===================================
-- ANNOUNCEMENTS TABLE
-- ===================================
CREATE TABLE Announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES Users(user_id) ON DELETE CASCADE
);
CREATE INDEX idx_announcements_admin ON Announcements(admin_id);

-- ===================================
-- ELECTIONS TABLE
-- ===================================

CREATE TABLE Elections (
    election_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    election_date DATETIME NOT NULL,
    picture VARCHAR(255) NULL,  -- New column for storing the picture path or URL
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP	
);

-- ===================================
-- SYSTEM LOGS TABLE
-- ===================================
CREATE TABLE System_Logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);
CREATE INDEX idx_logs_user ON System_Logs(user_id);

-- ===================================
-- RESIDENT MESSAGES TABLE
-- ===================================

CREATE TABLE Resident_Messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message_content TEXT NOT NULL,
    attached_file VARCHAR(255) NULL, -- Path or URL to the attached file
    status ENUM('pending', 'completed', 'rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_resident_messages_user ON Resident_Messages(user_id);
-- ===================================
-- COMPLAINTS TABLE
-- ===================================
CREATE TABLE Complaints (
    complaint_id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL,  -- The user_id of the resident being complained about
    complaint_details TEXT NOT NULL,
    fee DECIMAL(10, 2) NOT NULL,  -- Fee for filing the complaint
    status ENUM('pending', 'resolved', 'dismissed') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (resident_id) REFERENCES Users(user_id) ON DELETE CASCADE
);
CREATE INDEX idx_complaints_resident ON Complaints(resident_id);

