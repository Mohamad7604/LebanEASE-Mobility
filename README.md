# LebanEASE Mobility

LebanEASE Mobility is a PHP/MySQL bus reservation and operations management system developed for COE416 Software Engineering.

## Technologies Used
- PHP
- MySQL
- HTML/CSS/JavaScript
- XAMPP
- OpenStreetMap / OSRM
- OpenAI API for AI agent features

## Main Features

### Passenger Side
- Registration and login
- Trip search
- Seat reservation
- My reservations
- Ticket printing / PDF
- Passenger AI agent
- Route planner

### Admin Side
- Manage buses, drivers, routes, and schedules
- Manage reservations and maintenance
- Analytics dashboard
- Activity log
- System health dashboard
- CSV export / backup
- Admin operations AI agent
- Multi-agent Dispatch Bridge

## Local Setup

1. Copy the project into the XAMPP htdocs folder.
2. Start Apache and MySQL from XAMPP.
3. Open phpMyAdmin:
   http://localhost/phpmyadmin
4. Import the database file:
   database/LebanEase_updated.sql
5. Check database config:
   db/config.php

Default XAMPP settings:

```php
$host = "localhost";
$dbname = "lebanease";
$user = "root";
$pass = "";
