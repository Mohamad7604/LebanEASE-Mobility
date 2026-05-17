-- LebanEase Mobility updated database
-- IMPORTANT: Use this database only. Do NOT run the old "DROP DATABASE BusManagementSystem" lines.

DROP DATABASE IF EXISTS LebanEase;
CREATE DATABASE LebanEase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE LebanEase;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS Reservation;
DROP TABLE IF EXISTS Admin_Activity_Log;
DROP TABLE IF EXISTS Bus_Schedule;
DROP TABLE IF EXISTS Bus_Maintenance;
DROP TABLE IF EXISTS Route;
DROP TABLE IF EXISTS Bus;
DROP TABLE IF EXISTS Driver;
DROP TABLE IF EXISTS Passenger;
DROP TABLE IF EXISTS Admin;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE Driver (
    Driver_ID INT AUTO_INCREMENT PRIMARY KEY,
    First_Name VARCHAR(50) NOT NULL,
    Last_Name VARCHAR(50) NOT NULL,
    License_Number VARCHAR(50) NOT NULL UNIQUE,
    Phone VARCHAR(20) NOT NULL,
    Experience_Years INT NOT NULL DEFAULT 0,
    Driver_Status ENUM('Active','Unavailable','Inactive') NOT NULL DEFAULT 'Active'
);

CREATE TABLE Admin (
    Admin_ID INT AUTO_INCREMENT PRIMARY KEY,
    First_Name VARCHAR(50) NOT NULL,
    Last_Name VARCHAR(50) NOT NULL,
    Email VARCHAR(100) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL
);


CREATE TABLE Admin_Activity_Log (
    Log_ID INT AUTO_INCREMENT PRIMARY KEY,
    Admin_ID INT NULL,
    Event_Type VARCHAR(50) NOT NULL,
    Entity_Name VARCHAR(80) NOT NULL,
    Entity_ID INT NULL,
    Description TEXT NULL,
    Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_admin FOREIGN KEY (Admin_ID) REFERENCES Admin(Admin_ID)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_activity_created (Created_At),
    INDEX idx_activity_entity (Entity_Name, Entity_ID)
);

CREATE TABLE Bus (
    Bus_ID INT AUTO_INCREMENT PRIMARY KEY,
    Bus_Number VARCHAR(20) NOT NULL UNIQUE,
    Capacity INT NOT NULL,
    Type ENUM('Standard', 'MiniBus','Van','AirportShuttle','VIP', 'Luxury','LongDistance') NOT NULL,
    Status ENUM('Active','Maintenance','Offline','Unavailable') NOT NULL DEFAULT 'Active',
    Driver_ID INT NULL,
    Admin_ID INT NULL,
    CONSTRAINT fk_bus_driver FOREIGN KEY (Driver_ID) REFERENCES Driver(Driver_ID)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_bus_admin FOREIGN KEY (Admin_ID) REFERENCES Admin(Admin_ID)
        ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE Route (
    Route_ID INT AUTO_INCREMENT PRIMARY KEY,
    Route_Name VARCHAR(100) NOT NULL,
    Source VARCHAR(50) NOT NULL,
    Destination VARCHAR(50) NOT NULL,
    Distance DECIMAL(6,2) NOT NULL,
    Status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
);

CREATE TABLE Bus_Maintenance (
    Maintenance_ID INT AUTO_INCREMENT PRIMARY KEY,
    Date DATE NOT NULL,
    Description TEXT NOT NULL,
    Cost DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    Maintenance_Status ENUM('Open','Completed') NOT NULL DEFAULT 'Open',
    Bus_ID INT NOT NULL,
    CONSTRAINT fk_maintenance_bus FOREIGN KEY (Bus_ID) REFERENCES Bus(Bus_ID)
        ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE Bus_Schedule (
    Schedule_ID INT AUTO_INCREMENT PRIMARY KEY,
    Departure_Time TIME NOT NULL,
    Arrival_Time TIME NOT NULL,
    Date DATE NOT NULL,
    Bus_ID INT NOT NULL,
    Route_ID INT NOT NULL,
    Driver_ID INT NULL,
    Status ENUM('Scheduled','Cancelled') NOT NULL DEFAULT 'Scheduled',
    CONSTRAINT fk_schedule_bus FOREIGN KEY (Bus_ID) REFERENCES Bus(Bus_ID)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_route FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_driver FOREIGN KEY (Driver_ID) REFERENCES Driver(Driver_ID)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_schedule_search (Date, Route_ID, Status),
    INDEX idx_schedule_bus_time (Bus_ID, Date, Departure_Time, Arrival_Time),
    INDEX idx_schedule_driver_time (Driver_ID, Date, Departure_Time, Arrival_Time)
);

CREATE TABLE Passenger (
    Passenger_ID INT AUTO_INCREMENT PRIMARY KEY,
    First_Name VARCHAR(50) NOT NULL,
    Last_Name VARCHAR(50) NOT NULL,
    Email VARCHAR(100) NOT NULL UNIQUE,
    Phone VARCHAR(20) NOT NULL,
    Gender VARCHAR(10) NOT NULL,
    Username VARCHAR(50) NULL UNIQUE,
    Password VARCHAR(255) NULL
);

CREATE TABLE Reservation (
    Reservation_ID INT AUTO_INCREMENT PRIMARY KEY,
    Seat_Number INT NOT NULL,
    Booking_Date DATE NOT NULL,
    Status ENUM('Active','Cancelled') NOT NULL DEFAULT 'Active',
    Passenger_ID INT NOT NULL,
    Schedule_ID INT NOT NULL,
    Active_Seat_Number INT GENERATED ALWAYS AS (
        CASE WHEN Status = 'Active' THEN Seat_Number ELSE NULL END
    ) STORED,
    Active_Passenger_ID INT GENERATED ALWAYS AS (
        CASE WHEN Status = 'Active' THEN Passenger_ID ELSE NULL END
    ) STORED,
    CONSTRAINT fk_res_passenger FOREIGN KEY (Passenger_ID) REFERENCES Passenger(Passenger_ID)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_res_schedule FOREIGN KEY (Schedule_ID) REFERENCES Bus_Schedule(Schedule_ID)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_res_schedule_status (Schedule_ID, Status),
    INDEX idx_res_passenger_status (Passenger_ID, Status),
    UNIQUE KEY uq_active_seat_per_trip (Schedule_ID, Active_Seat_Number),
    UNIQUE KEY uq_active_passenger_per_trip (Schedule_ID, Active_Passenger_ID)
);



-- Dashboard and health-check indexes
CREATE INDEX idx_route_source_destination ON Route (Source, Destination, Status);
CREATE INDEX idx_schedule_date ON Bus_Schedule (Date, Status);
CREATE INDEX idx_reservation_status ON Reservation (Status);
CREATE INDEX idx_reservation_passenger_schedule ON Reservation (Passenger_ID, Schedule_ID, Status);
CREATE INDEX idx_bus_status ON Bus (Status);
CREATE INDEX idx_passenger_email ON Passenger (Email);
CREATE INDEX idx_admin_email ON Admin (Email);

INSERT INTO Admin (First_Name, Last_Name, Email, Password) VALUES
('System', 'Admin', 'admin@lebanease.com', '$2y$12$RiJ2K7IvJTeXf8bG5Y5A1ORnE3A1qwyhoLR4N0m7ox3nwSnAyDLjq'),
('Operations', 'Manager', 'manager@lebanease.com', '$2y$12$RiJ2K7IvJTeXf8bG5Y5A1ORnE3A1qwyhoLR4N0m7ox3nwSnAyDLjq');

INSERT INTO Driver (First_Name, Last_Name, License_Number, Phone, Experience_Years, Driver_Status) VALUES
('Ali', 'Khalil', 'DRV-1001', '71000001', 8, 'Active'),
('Omar', 'Haddad', 'DRV-1002', '71000002', 6, 'Active'),
('Nour', 'Mansour', 'DRV-1003', '71000003', 5, 'Active'),
('Karim', 'Sabbagh', 'DRV-1004', '71000004', 10, 'Unavailable'),
('Maya', 'Younes', 'DRV-1005', '71000005', 4, 'Active');

INSERT INTO Bus (Bus_Number, Capacity, Type, Status, Driver_ID, Admin_ID) VALUES
('BUS-001', 50, 'Standard', 'Active', 1, 1),
('BUS-002', 40, 'Luxury', 'Active', 2, 1),
('BUS-003', 45, 'Standard', 'Active', 3, 1),
('BUS-004', 55, 'Standard', 'Maintenance', 4, 1),
('BUS-005', 35, 'VIP', 'Active', 5, 1),
('BUS-006', 30, 'MiniBus', 'Active', 1, 2),
('BUS-007', 25, 'Van', 'Active', 2, 2),
('BUS-008', 50, 'AirportShuttle', 'Active', 3, 2),
('BUS-009', 52, 'LongDistance', 'Active', 5, 2),
('BUS-010', 42, 'Standard', 'Offline', NULL, 2);

INSERT INTO Route (Route_Name, Source, Destination, Distance, Status) VALUES
('Beirut to Tripoli', 'Beirut', 'Tripoli', 85.00, 'Active'),
('Tripoli to Beirut', 'Tripoli', 'Beirut', 85.00, 'Active'),
('Beirut to Saida', 'Beirut', 'Saida', 45.00, 'Active'),
('Saida to Beirut', 'Saida', 'Beirut', 45.00, 'Active'),
('Beirut to Zahle', 'Beirut', 'Zahle', 55.00, 'Active'),
('Zahle to Beirut', 'Zahle', 'Beirut', 55.00, 'Active'),
('Beirut to Airport', 'Beirut', 'Airport', 9.50, 'Active');

INSERT INTO Bus_Schedule (Departure_Time, Arrival_Time, Date, Bus_ID, Route_ID, Driver_ID, Status) VALUES
('08:00:00', '10:00:00', '2026-06-01', 1, 1, 1, 'Scheduled'),
('12:00:00', '14:00:00', '2026-06-01', 2, 2, 2, 'Scheduled'),
('09:30:00', '10:30:00', '2026-06-02', 3, 3, 3, 'Scheduled'),
('16:00:00', '17:00:00', '2026-06-02', 5, 4, 5, 'Scheduled'),
('07:30:00', '08:45:00', '2026-06-03', 6, 5, 1, 'Scheduled'),
('18:00:00', '19:15:00', '2026-06-03', 7, 6, 2, 'Scheduled'),
('05:30:00', '06:00:00', '2026-06-04', 8, 7, 3, 'Scheduled'),
('22:00:00', '00:30:00', '2026-06-05', 9, 1, 5, 'Scheduled');

INSERT INTO Bus_Maintenance (Date, Description, Cost, Maintenance_Status, Bus_ID) VALUES
('2026-05-05', 'Engine inspection and oil replacement', 120.00, 'Completed', 1),
('2026-05-10', 'Brake system issue under review', 180.00, 'Open', 4),
('2026-05-11', 'Interior cleaning and AC check', 60.00, 'Completed', 2);

INSERT INTO Passenger (First_Name, Last_Name, Email, Phone, Gender, Username, Password) VALUES
('Hassane', 'Jaber', 'hassane@example.com', '76000001', 'Male', 'hassane', '$2y$12$eNvWqEX9IB.n45vaN4w5J.KLtIhdUIqYVZqDOe0Zrw9JUBYWcwRhO'),
('Adam', 'Tabaja', 'adam@example.com', '76000002', 'Male', 'adam', '$2y$12$eNvWqEX9IB.n45vaN4w5J.KLtIhdUIqYVZqDOe0Zrw9JUBYWcwRhO');

INSERT INTO Reservation (Seat_Number, Booking_Date, Status, Passenger_ID, Schedule_ID) VALUES
(1, CURDATE(), 'Active', 1, 1),
(2, CURDATE(), 'Active', 2, 1),
(5, CURDATE(), 'Active', 1, 3);



INSERT INTO Admin_Activity_Log (Admin_ID, Event_Type, Entity_Name, Entity_ID, Description, Created_At) VALUES
(1, 'SYSTEM_READY', 'System', NULL, 'Initial demo database imported successfully.', NOW());

CREATE OR REPLACE VIEW v_schedule_overview AS
SELECT
    bs.Schedule_ID,
    bs.Departure_Time,
    bs.Arrival_Time,
    bs.Date,
    bs.Status AS Schedule_Status,
    b.Bus_ID,
    b.Bus_Number,
    b.Type AS Bus_Type,
    b.Capacity,
    b.Status AS Bus_Status,
    d.Driver_ID,
    CONCAT(d.First_Name, ' ', d.Last_Name) AS Driver_Name,
    d.Driver_Status,
    r.Route_ID,
    r.Route_Name,
    r.Source,
    r.Destination,
    r.Distance,
    GREATEST(3.00, 2.00 + (r.Distance * 0.15)) AS Estimated_Fare,
    (
      SELECT COUNT(*)
      FROM Reservation res
      WHERE res.Schedule_ID = bs.Schedule_ID AND res.Status = 'Active'
    ) AS Reserved_Seats,
    (
      b.Capacity - (
        SELECT COUNT(*)
        FROM Reservation res2
        WHERE res2.Schedule_ID = bs.Schedule_ID AND res2.Status = 'Active'
      )
    ) AS Available_Seats,
    CASE
      WHEN bs.Status = 'Cancelled' THEN 'Cancelled'
      WHEN (
        CASE
          WHEN bs.Arrival_Time <= bs.Departure_Time
            THEN DATE_ADD(TIMESTAMP(bs.Date, bs.Arrival_Time), INTERVAL 1 DAY)
          ELSE TIMESTAMP(bs.Date, bs.Arrival_Time)
        END
      ) < NOW() THEN 'Completed'
      ELSE 'Scheduled'
    END AS Trip_Status
FROM Bus_Schedule bs
JOIN Bus b ON bs.Bus_ID = b.Bus_ID
JOIN Route r ON bs.Route_ID = r.Route_ID
LEFT JOIN Driver d ON bs.Driver_ID = d.Driver_ID;

DELIMITER $$
CREATE PROCEDURE sp_create_reservation_with_passenger(
    IN p_first_name VARCHAR(50),
    IN p_last_name VARCHAR(50),
    IN p_email VARCHAR(100),
    IN p_phone VARCHAR(20),
    IN p_gender VARCHAR(10),
    IN p_schedule_id INT,
    IN p_seat_number INT,
    OUT out_passenger_id INT,
    OUT out_reservation_id INT
)
BEGIN
    DECLARE existing_seat_count INT DEFAULT 0;
    DECLARE existing_passenger_res_count INT DEFAULT 0;
    DECLARE bus_capacity INT DEFAULT 0;

    START TRANSACTION;

    SELECT b.Capacity INTO bus_capacity
    FROM Bus_Schedule bs
    JOIN Bus b ON bs.Bus_ID = b.Bus_ID
    WHERE bs.Schedule_ID = p_schedule_id
    LIMIT 1;

    IF bus_capacity IS NULL OR p_seat_number < 1 OR p_seat_number > bus_capacity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid seat number';
    END IF;

    SELECT COUNT(*) INTO existing_seat_count
    FROM Reservation
    WHERE Schedule_ID = p_schedule_id
      AND Seat_Number = p_seat_number
      AND Status = 'Active';

    IF existing_seat_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seat already reserved';
    END IF;

    SELECT Passenger_ID INTO out_passenger_id
    FROM Passenger
    WHERE Email = p_email
    LIMIT 1;

    IF out_passenger_id IS NULL THEN
        INSERT INTO Passenger (First_Name, Last_Name, Email, Phone, Gender)
        VALUES (p_first_name, p_last_name, p_email, p_phone, p_gender);
        SET out_passenger_id = LAST_INSERT_ID();
    END IF;

    SELECT COUNT(*) INTO existing_passenger_res_count
    FROM Reservation
    WHERE Schedule_ID = p_schedule_id
      AND Passenger_ID = out_passenger_id
      AND Status = 'Active';

    IF existing_passenger_res_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passenger already has an active reservation for this trip';
    END IF;

    INSERT INTO Reservation (Seat_Number, Booking_Date, Status, Passenger_ID, Schedule_ID)
    VALUES (p_seat_number, CURDATE(), 'Active', out_passenger_id, p_schedule_id);

    SET out_reservation_id = LAST_INSERT_ID();
    COMMIT;
END$$
DELIMITER ;
