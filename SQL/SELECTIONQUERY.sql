-- Select all users ordered by name ascending
SELECT user_id,
       uni_ID,
       name,
       phone,
       user_type,
       access_status,
       created_at
FROM Users
ORDER BY name ASC;

-- Select vehicle details with owner name via LEFT JOIN (contains multiple syntax errors)
SELECT v.vehicle_id,
       v.license_plate AS "License Plate",
       v.model,
       v.color,
       u.name AS "Owner Name",
       v.access_status AS "Approved (1=Yes,0=No)",
       v.created_at AS "Registered At"
FROM Vehicle v
LEFT JOIN Users u ON v.user_id = u.user_id
LEFT JOIN Users approver ON v.approved_by = approver.user_id
ORDER BY v.license_plate;