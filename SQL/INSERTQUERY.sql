IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Parking_garage' AND COLUMN_NAME = 'garage_status')
BEGIN
    ALTER TABLE Parking_garage ADD garage_status VARCHAR(10) DEFAULT 'Open' CHECK (garage_status IN ('Open', 'Closed'));
    -- Set all existing garages to 'Open' by default
    UPDATE Parking_garage SET garage_status = 'Open' WHERE garage_status IS NULL;
END

SET IDENTITY_INSERT Parking_garage ON;

INSERT INTO Parking_garage (garage_id, name, garage_access, garage_status) VALUES 
(1, 'Main building Garage', 1, 'Open'),
(2, 'ITI Garage', 1, 'Open'),
(3, 'NAID Garage', 0, 'Closed'),
(4, 'Innovation Garage', 1, 'Open');

SET IDENTITY_INSERT Parking_garage OFF;

-- Insert Parking Spots
SET IDENTITY_INSERT Parking_spots ON;

INSERT INTO Parking_spots (spot_id, spot_number, spot_status, garage_id) VALUES 
(1, 'N-101', 'Available', 1),
(2, 'N-102', 'Available', 1),
(3, 'N-103', 'Available', 1),
(4, 'N-104', 'Available', 1),
(5, 'N-105', 'Available', 1),
(6, 'N-106', 'Available', 1),
(7, 'N-107', 'Available', 1),
(8, 'N-108', 'Available', 1),
(9, 'S-201', 'Available', 2),
(10, 'S-202', 'Available', 2),
(11, 'S-203', 'Available', 2),
(12, 'S-204', 'Available', 2),
(13, 'S-205', 'Available', 2),
(14, 'S-206', 'Available', 2),
(15, 'S-207', 'Available', 2),
(16, 'S-208', 'Available', 2),
(17, 'S-209', 'Available', 2),
(18, 'S-210', 'Available', 2),
(19, 'E-301', 'Available', 3),
(20, 'E-302', 'Available', 3),
(21, 'E-303', 'Available', 3),
(22, 'E-304', 'Available', 3),
(23, 'E-305', 'Available', 3),
(24, 'E-306', 'Available', 3),
(25, 'W-401', 'Available', 4),
(26, 'W-402', 'Available', 4),
(27, 'W-403', 'Available', 4),
(28, 'W-404', 'Available', 4),
(29, 'W-405', 'Available', 4),
(30, 'C-501', 'Available', 5),
(31, 'C-502', 'Available', 5),
(32, 'C-503', 'Available', 5),
(33, 'C-504', 'Available', 5),
(34, 'C-505', 'Available', 5),
(35, 'C-506', 'Available', 5),
(36, 'C-507', 'Available', 5);

SET IDENTITY_INSERT Parking_spots OFF;

-- Insert Service Types
SET IDENTITY_INSERT Service_type ON;

INSERT INTO Service_type (service_id, service_name, description, price) VALUES 
(1, 'Car Wash', 'Professional car wash service including exterior and interior cleaning', 50.00),
(2, 'Oil Change', 'Standard oil change service with quality motor oil', 150.00),
(3, 'Tire Rotation', 'Rotate tires to ensure even wear and extend tire life', 75.00);

SET IDENTITY_INSERT Service_type OFF;

-- Insert a new staff user (Mona Adel)
INSERT INTO Users (uni_ID, name, user_type, access_status)
VALUES ('EUI20251235', 'Mona Adel', 'Stafff', 1);

-- Insert a new vehicle record (Ford Mustang for user_id 4)
INSERT INTO Vehicle (license_plate, color, model, user_id, access_status, approved_by)
VALUES ('DEF-9012', 'Black', 'Ford Mustang', 4, 1, 1);