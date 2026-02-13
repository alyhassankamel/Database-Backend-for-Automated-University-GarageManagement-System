--Total Revenue Generated from Paid Invoices
SELECT 
    SUM(p.amount) AS Total_Revenue
FROM Payment p
WHERE p.status = 'Paid';

--Number of Service Requests per Service Type
SELECT 
    st.service_name,
    COUNT(sr.request_id) AS Total_Requests
FROM Service_type st
JOIN Service_request sr ON st.service_id = sr.service_id
GROUP BY st.service_name;