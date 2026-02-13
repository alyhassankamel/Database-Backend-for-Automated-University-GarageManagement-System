# EUI Parking Management System

A comprehensive parking management system for Egypt University of Informatics.

## Project Structure

```
Project/
├── auth/              # Authentication (Login, Register, Logout)
├── user/              # User pages (Dashboard, Vehicles, Requests, Payment)
├── admin/             # Admin pages (Manage Requests)
├── manager/           # Manager pages (Full Management)
├── garage/            # Garage management (for future use)
├── assets/            # Static files (CSS, JS, Images)
├── config/            # Configuration (Database)
├── includes/          # Shared includes (Header, Footer)
└── SQLQuery1.sql      # Database schema
```

## Requirements

- XAMPP (Apache + PHP 8.2+)
- SQL Server (with SQL Server Authentication or Windows Authentication)
- SQL Server ODBC Driver 17 or 18
- PHP SQL Server extensions (sqlsrv, pdo_sqlsrv)

## Setup

1. **Database Setup:**
   - Create a database named `AUGMS` in SQL Server
   - Run `SQLQuery1.sql` to create all tables

2. **Database Configuration:**
   - Edit `config/Database.php`
   - Update `$server` with your SQL Server instance name
   - Update `BASE_URL` if your project folder name is different

3. **Access the Application:**
   - Start Apache in XAMPP
   - Navigate to: `http://localhost/Project/`

## Default Access

- No default users exist - register your first account
- First user can be any type (Student, Staff, Admin, Manager)
- Admin/Manager accounts can manage requests and users

## Features

- **User Features:** Vehicle registration, Service requests, Payment processing
- **Admin Features:** Request management, Approval/Rejection
- **Manager Features:** Full system management, User management, Garage management

## Notes

- The system uses Windows Authentication by default
- All paths are managed centrally in `config/Database.php`
- The `garage/` folder is reserved for future garage management features

