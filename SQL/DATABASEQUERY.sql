Create Database AUGMS;
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