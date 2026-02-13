-- Invoice, Payment, and Related Service Details
SELECT 
    i.invoice_id AS 'Invoice ID',
    i.amount AS 'Amount',
    i.date_issued AS 'Date Issued',
    i.time_issued AS 'Time Issued',
    
    p.payment_id AS 'Payment ID',
    p.amount AS 'Paid Amount',
    p.date_paid AS 'Date Paid',
    p.time_paid AS 'Time Paid',
    p.status AS 'Payment Status',
    
    pm.method_type AS 'Payment Method Type',
    pm.payment_info AS 'Payment Credentials/Details',
    
    sr.request_id AS 'Related Service Request ID',
    u.uni_ID AS 'User University ID',
    u.name AS 'User Name',
    v.license_plate AS 'Vehicle License Plate'
FROM Invoice i
LEFT JOIN Payment p ON i.invoice_id = p.invoice_id
LEFT JOIN Payment_method pm ON p.method_id = pm.method_id
LEFT JOIN Service_request sr ON i.request_id = sr.request_id
LEFT JOIN Users u ON sr.user_id = u.user_id
LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id
ORDER BY i.date_issued DESC, i.invoice_id DESC;

--Total Payments per Payment Method
SELECT 
    pm.method_type,
    SUM(p.amount) AS Total_Amount
FROM Payment p
JOIN Payment_method pm ON p.method_id = pm.method_id
GROUP BY pm.method_type;

--Number of Service Requests per Garage
SELECT 
    pg.name AS 'Garage Name',
    COUNT(sr.request_id) AS 'Total Service Requests'
FROM Parking_garage pg
JOIN Parking_spots ps ON pg.garage_id = ps.garage_id
JOIN Service_request sr ON ps.spot_id = sr.spot_id
GROUP BY pg.name
ORDER BY COUNT(sr.request_id) DESC;

--Service Requests with Assigned Parking Spots and Garages
SELECT 
    sr.request_id,
    u.name AS User_Name,
    v.license_plate,
    ps.spot_number,
    pg.name AS Garage_Name,
    sr.status,
    sr.created_at
FROM Service_request sr
JOIN Users u ON sr.user_id = u.user_id
JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id
JOIN Parking_spots ps ON sr.spot_id = ps.spot_id
JOIN Parking_garage pg ON ps.garage_id = pg.garage_id;