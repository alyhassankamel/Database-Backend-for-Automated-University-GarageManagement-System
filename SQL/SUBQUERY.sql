--Users Who Have Made More Than One Service Request
SELECT name
FROM Users
WHERE user_id IN (
    SELECT user_id
    FROM Service_request
    GROUP BY user_id
    HAVING COUNT(request_id) > 1
);

--Vehicles That Have Never Been Used in Any Service Request
SELECT license_plate
FROM Vehicle
WHERE vehicle_id NOT IN (
    SELECT DISTINCT vehicle_id
    FROM Service_request
);