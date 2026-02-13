-- =============================================
-- Parking Management System Database Schema
-- Fully constrained for Microsoft SQL Server
-- =============================================

CREATE TABLE Users (
    user_id INT IDENTITY(1,1) PRIMARY KEY,
    uni_ID VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255),
    user_type VARCHAR(50),
    access_status BIT,
    created_at DATETIME DEFAULT GETDATE()
);


CREATE TABLE Vehicle (
    vehicle_id INT IDENTITY(1,1) PRIMARY KEY,
    license_plate VARCHAR(20) UNIQUE NOT NULL,
    color VARCHAR(30),
    user_id INT,
    model VARCHAR(50),
    access_status BIT,
    approved_by INT,
    created_at DATETIME DEFAULT GETDATE(),
    FOREIGN KEY (user_id) REFERENCES Users(user_id),
    FOREIGN KEY (approved_by) REFERENCES Users(user_id)
);



CREATE TABLE Service_type (
    service_id INT IDENTITY(1,1) PRIMARY KEY,
    service_name VARCHAR(100),
    description VARCHAR(255),
    price DECIMAL(10,2) DEFAULT 0.00
);



CREATE TABLE Parking_garage (
    garage_id INT IDENTITY(1,1) PRIMARY KEY,
    name VARCHAR(100),
    garage_access BIT,
    garage_status VARCHAR(10) DEFAULT 'Open' CHECK (garage_status IN ('Open', 'Closed')),
    location VARCHAR(255)
);


CREATE TABLE Gates (
    gate_id INT IDENTITY(1,1) PRIMARY KEY,
    garage_id INT,
    name VARCHAR(50),
    FOREIGN KEY (garage_id) REFERENCES Parking_garage(garage_id)
);


CREATE TABLE Parking_spots (
    spot_id INT IDENTITY(1,1) PRIMARY KEY,
    spot_number VARCHAR(20),
    spot_status VARCHAR(30),
    garage_id INT,
    FOREIGN KEY (garage_id) REFERENCES Parking_garage(garage_id)
);



CREATE TABLE Occupancy_sensor (
    sensor_id INT PRIMARY KEY,
    spot_id INT,
    is_occupied BIT,
    FOREIGN KEY (spot_id) REFERENCES Parking_spots(spot_id)
);


CREATE TABLE Gate_sensors (
    sensor_id INT PRIMARY KEY,
    gate_id INT,
    FOREIGN KEY (gate_id) REFERENCES Gates(gate_id)
);


CREATE TABLE Sensors (
    sensor_id INT IDENTITY(1,1) PRIMARY KEY,
    sensor_type VARCHAR(50),
    spot_id INT,
    FOREIGN KEY (spot_id) REFERENCES Parking_spots(spot_id)
);


CREATE TABLE Service_request (
    request_id INT IDENTITY(1,1) PRIMARY KEY,
    user_id INT,
    vehicle_id INT,
    service_id INT,
    status VARCHAR(30),
    approved_by INT,
    created_at DATETIME DEFAULT GETDATE(),
    completed_at DATETIME,
    spot_id INT,
    FOREIGN KEY (user_id) REFERENCES Users(user_id),
    FOREIGN KEY (vehicle_id) REFERENCES Vehicle(vehicle_id),
    FOREIGN KEY (service_id) REFERENCES Service_type(service_id),
    FOREIGN KEY (approved_by) REFERENCES Users(user_id),
    FOREIGN KEY (spot_id) REFERENCES Parking_spots(spot_id)
);


CREATE TABLE Invoice (
    invoice_id INT IDENTITY(1,1) PRIMARY KEY,
    request_id INT,
    amount DECIMAL(10,2),
    date_issued DATE,
    time_issued TIME,
    FOREIGN KEY (request_id) REFERENCES Service_request(request_id)
);


CREATE TABLE Payment_method (
    method_id INT IDENTITY(1,1) PRIMARY KEY,
    method_type VARCHAR(50),
    payment_info VARCHAR(255)
);


CREATE TABLE Payment (
    payment_id INT IDENTITY(1,1) PRIMARY KEY,
    invoice_id INT,
    amount DECIMAL(10,2),
    date_paid DATE,
    time_paid TIME,
    status VARCHAR(30),
    method_id INT,
    FOREIGN KEY (invoice_id) REFERENCES Invoice(invoice_id),
    FOREIGN KEY (method_id) REFERENCES Payment_method(method_id)
);


CREATE TABLE Reports (
    report_id INT IDENTITY(1,1) PRIMARY KEY,
    report_type VARCHAR(50),
    user_id INT,
    date_generated DATE,
    time_generated TIME,
    details VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES Users(user_id)
);


CREATE TABLE Log_record (
    log_id INT IDENTITY(1,1) PRIMARY KEY,
    spot_id INT,
    gate_id INT,
    request_id INT,
    report_id INT,
    vehicle_id INT,
    user_id INT,
    sensor_id INT,
    log_type VARCHAR(50),
    details VARCHAR(255),
    timestamp DATETIME,
    FOREIGN KEY (spot_id) REFERENCES Parking_spots(spot_id),
    FOREIGN KEY (gate_id) REFERENCES Gates(gate_id),
    FOREIGN KEY (request_id) REFERENCES Service_request(request_id),
    FOREIGN KEY (report_id) REFERENCES Reports(report_id),
    FOREIGN KEY (vehicle_id) REFERENCES Vehicle(vehicle_id),
    FOREIGN KEY (user_id) REFERENCES Users(user_id),
    FOREIGN KEY (sensor_id) REFERENCES Sensors(sensor_id)
);

-- =============================================
-- Relationship Tables (M:N)
-- =============================================

CREATE TABLE Requests (
    user_id INT,
    report_id INT,
    PRIMARY KEY (user_id, report_id),
    FOREIGN KEY (user_id) REFERENCES Users(user_id),
    FOREIGN KEY (report_id) REFERENCES Reports(report_id)
);


CREATE TABLE Controls (
    user_id INT,
    garage_id INT,
    PRIMARY KEY (user_id, garage_id),
    FOREIGN KEY (user_id) REFERENCES Users(user_id),
    FOREIGN KEY (garage_id) REFERENCES Parking_garage(garage_id)
);


CREATE TABLE Owns (
    vehicle_id INT,
    request_id INT,
    PRIMARY KEY (vehicle_id, request_id),
    FOREIGN KEY (vehicle_id) REFERENCES Vehicle(vehicle_id),
    FOREIGN KEY (request_id) REFERENCES Service_request(request_id)
);

-- =============================================
-- Sample Data Insertions
-- =============================================

-- Insert Parking Garages
-- Add garage_status column if it doesn't exist
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Parking_garage' AND COLUMN_NAME = 'garage_status')
BEGIN
    ALTER TABLE Parking_garage ADD garage_status VARCHAR(10) DEFAULT 'Open' CHECK (garage_status IN ('Open', 'Closed'));
    -- Set all existing garages to 'Open' by default
    UPDATE Parking_garage SET garage_status = 'Open' WHERE garage_status IS NULL;
END

SET IDENTITY_INSERT Parking_garage ON;

-- Insert Parking Garages (only if they don't exist)
IF NOT EXISTS (SELECT 1 FROM Parking_garage WHERE garage_id = 1)
    INSERT INTO Parking_garage (garage_id, name, garage_access, garage_status) VALUES (1, 'Main building Garage', 1, 'Open');

IF NOT EXISTS (SELECT 1 FROM Parking_garage WHERE garage_id = 2)
    INSERT INTO Parking_garage (garage_id, name, garage_access, garage_status) VALUES (2, 'ITI Garage', 1, 'Open');

IF NOT EXISTS (SELECT 1 FROM Parking_garage WHERE garage_id = 3)
    INSERT INTO Parking_garage (garage_id, name, garage_access, garage_status) VALUES (3, 'NAID Garage', 0, 'Closed');

IF NOT EXISTS (SELECT 1 FROM Parking_garage WHERE garage_id = 4)
    INSERT INTO Parking_garage (garage_id, name, garage_access, garage_status) VALUES (4, 'Innovation Garage', 1, 'Open');

SET IDENTITY_INSERT Parking_garage OFF;

-- Insert Parking Spots
SET IDENTITY_INSERT Parking_spots ON;

-- Insert spots only if they don't exist (removed garage_id 5 references since only 4 garages exist)
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 1)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (1, 'N-101', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 2)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (2, 'N-102', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 3)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (3, 'N-103', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 4)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (4, 'N-104', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 5)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (5, 'N-105', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 6)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (6, 'N-106', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 7)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (7, 'N-107', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 8)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (8, 'N-108', 'Available', 1);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 9)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (9, 'S-201', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 10)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (10, 'S-202', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 11)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (11, 'S-203', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 12)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (12, 'S-204', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 13)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (13, 'S-205', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 14)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (14, 'S-206', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 15)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (15, 'S-207', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 16)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (16, 'S-208', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 17)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (17, 'S-209', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 18)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (18, 'S-210', 'Available', 2);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 19)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (19, 'E-301', 'Available', 3);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 20)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (20, 'E-302', 'Available', 3);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 21)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (21, 'E-303', 'Available', 3);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 22)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (22, 'E-304', 'Available', 3);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 23)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (23, 'E-305', 'Available', 3);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 24)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (24, 'E-306', 'Available', 3);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 25)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (25, 'W-401', 'Available', 4);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 26)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (26, 'W-402', 'Available', 4);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 27)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (27, 'W-403', 'Available', 4);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 28)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (28, 'W-404', 'Available', 4);
IF NOT EXISTS (SELECT 1 FROM Parking_spots WHERE spot_id = 29)
    INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES (29, 'W-405', 'Available', 4);

SET IDENTITY_INSERT Parking_spots OFF;

-- Insert Service Types
SET IDENTITY_INSERT Service_type ON;

INSERT INTO Service_type (service_id, service_name, description, price) VALUES 
(1, 'Car Wash', 'Professional car wash service including exterior and interior cleaning', 50.00),
(2, 'Oil Change', 'Standard oil change service with quality motor oil', 150.00),
(3, 'Tire Rotation', 'Rotate tires to ensure even wear and extend tire life', 75.00);

SET IDENTITY_INSERT Service_type OFF;
