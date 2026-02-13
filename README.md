# AUGMS - Advanced University Garage Management System

A sophisticated parking management system designed to demonstrate comprehensive data storage and retrieval capabilities through a practical web interface.

## Overview

This project showcases advanced data management techniques through a fully functional parking management system. The web interface serves as a practical demonstration of complex database interactions, including multi-table relationships, transaction processing, and real-time data operations.

## System Architecture

### Database Layer
The system is built on a robust relational database schema with 15 interconnected tables:

- **Core Entities**: Users, Vehicles, Service Types, Parking Garages
- **Management**: Service Requests, Invoices, Payments, Reports
- **Infrastructure**: Parking Spots, Gates, Sensors, Log Records
- **Relationships**: Complex many-to-many mappings through junction tables

### Query Demonstrations
The system implements various advanced query patterns:

#### Selection & Filtering
- Ordered data retrieval with multi-column sorting
- Conditional filtering with complex WHERE clauses
- Date and time-based queries

#### Join Operations
- Multi-table joins spanning 6+ tables
- LEFT JOIN for optional relationships
- Complex data aggregation across related entities

#### Advanced SQL Features
- **Subqueries**: Nested queries for complex filtering
- **Aggregations**: SUM, COUNT, GROUP BY operations
- **Transactions**: Multi-step data operations
- **Constraints**: Foreign keys, CHECK constraints, UNIQUE indexes

## Project Structure

```
AUGMS - Database/
├── SQL/                    # Database schema and query examples
│   ├── DATABASEQUERY.sql   # Complete table definitions
│   ├── SELECTIONQUERY.sql  # Data retrieval examples
│   ├── JOINSQUERY.sql      # Complex join operations
│   ├── AGGREGATEQUERY.sql  # Aggregate functions
│   ├── SUBQUERY.sql        # Nested query examples
│   └── [CRUD operations]   # Insert, Update, Delete examples
├── UI/                     # Web interface demonstrating database usage
│   ├── auth/               # User authentication system
│   ├── user/               # User dashboard and operations
│   ├── admin/              # Administrative interface
│   ├── manager/            # Management dashboard
│   ├── config/             # Database configuration
│   └── includes/           # Shared components
└── Documentation/          # Technical specifications
```

## Key Features Demonstrated

### Data Integrity
- Referential integrity through foreign key constraints
- Data validation with CHECK constraints
- Unique constraints for business rules

### Performance Optimization
- Indexed primary keys for fast lookups
- Optimized join queries
- Efficient aggregation operations

### Real-world Applications
- Transaction processing (payments, invoices)
- Audit logging (comprehensive log records)
- Reporting system with data aggregation
- Role-based access control

## Technical Implementation

### Database Design Patterns
- **Normalization**: 3NF compliance with proper relationships
- **Indexing Strategy**: Primary keys and foreign key indexes
- **Data Types**: Appropriate SQL Server data types
- **Constraints**: Business rules enforced at database level

### Query Complexity Examples

**Multi-table Join Analysis:**
```sql
-- Comprehensive payment and service analysis
SELECT i.invoice_id, p.payment_id, u.name, v.license_plate
FROM Invoice i
LEFT JOIN Payment p ON i.invoice_id = p.invoice_id
LEFT JOIN Service_request sr ON i.request_id = sr.request_id
LEFT JOIN Users u ON sr.user_id = u.user_id
LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id
```

**Advanced Subquery Filtering:**
```sql
-- Users with high activity
SELECT name FROM Users
WHERE user_id IN (
    SELECT user_id FROM Service_request
    GROUP BY user_id HAVING COUNT(request_id) > 1
)
```

**Aggregation with Grouping:**
```sql
-- Revenue analysis by service type
SELECT st.service_name, COUNT(sr.request_id) AS Total_Requests
FROM Service_type st
JOIN Service_request sr ON st.service_id = sr.service_id
GROUP BY st.service_name
```

## Setup Requirements

### Database
- SQL Server (2019+ recommended)
- ODBC Driver 17/18 for connectivity
- Database creation permissions

### Web Interface
- PHP 8.2+ with SQL Server extensions
- Apache/Nginx web server
- SQL Server authentication configuration

## Installation

1. **Database Setup**
   ```sql
   -- Execute DATABASEQUERY.sql to create schema
   CREATE Database AUGMS;
   -- Run the complete schema file
   ```

2. **Configure Connection**
   - Update `UI/config/Database.php` with server details
   - Set appropriate authentication method

3. **Deploy Web Interface**
   - Place UI folder in web root
   - Configure web server for PHP
   - Access through browser

## Data Operations Demonstrated

### CRUD Operations
- **Create**: User registration, vehicle registration, service requests
- **Read**: Complex reporting, dashboard analytics, search operations
- **Update**: Status changes, payment processing, approval workflows
- **Delete**: Controlled data removal with audit trails

### Business Logic
- Service request workflow management
- Payment processing with invoice generation
- Multi-level approval systems
- Real-time status tracking

## Query Performance Examples

The system demonstrates various optimization techniques:
- Efficient JOIN operations across multiple tables
- Indexed lookups for primary key access
- Aggregate queries with proper grouping
- Subquery optimization for complex filtering

## Data Relationships

Complex relationships include:
- Users → Vehicles (one-to-many)
- Vehicles → Service Requests (one-to-many)
- Service Requests → Invoices → Payments (transactional chain)
- Parking Garages → Spots → Sensors (hierarchical structure)
- Many-to-many relationships through junction tables

This system serves as a comprehensive demonstration of advanced database concepts through practical implementation, showing how complex data operations power real-world applications.
