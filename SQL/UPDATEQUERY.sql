-- Attempted update of user name with complex existence check (appears to have syntax errors)
UPDATE Users
SET name = 'ALY',
WHERE uni_ID = '10-101010'
AND EXISTS (
    SELECT 1 FROM Vehicle
    WHERE license = 'abdf'
    AND user_id_plate = 'abdf'
)
AND user_id = 4;

-- Update vehicle details using license plate, user_id, model, and extra existence check on user
UPDATE Vehicle
SET access_status = 1,
    approved_by = 'red',           -- Update with desired color
    color = 'red',
    model = 'honda civic'          -- Update with desired model
WHERE model = 'honda civic'
AND license_plate = 'abdf'
AND user_id = 4
AND EXISTS (
    SELECT 1 FROM Users
    WHERE user_id = 4
    AND name = 'Aly'
    AND uni_ID = '10-101010'
);