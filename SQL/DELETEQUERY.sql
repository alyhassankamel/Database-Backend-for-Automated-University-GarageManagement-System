-- Delete vehicle 'ABC123' along with all related service requests, invoices, and payments (cascading delete)
DELETE FROM Payment
WHERE invoice_id IN (
    SELECT i.invoice_id
    FROM Invoice i
    JOIN Service_request sr ON i.request_id = sr.request_id
    WHERE sr.vehicle_id = (SELECT vehicle_id FROM Vehicle WHERE license_plate = 'ABC123')
);

DELETE FROM Invoice
WHERE request_id IN (
    SELECT request_id
    FROM Service_request
    WHERE vehicle_id = (SELECT vehicle_id FROM Vehicle WHERE license_plate = 'ABC123')
);

DELETE FROM Service_request
WHERE vehicle_id = (SELECT vehicle_id FROM Vehicle WHERE license_plate = 'ABC123');

DELETE FROM Vehicle
WHERE license_plate = 'ABC123';