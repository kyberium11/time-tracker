# Time Tracking System

A modern time tracking application built with Laravel 11, Vue.js 3, and Tailwind CSS.

## Features

- **Authentication**: Secure login and registration using Laravel Breeze
- **User Roles**: Admin, Manager, and Employee roles with different access levels
- **Time Tracking**: Clock in/out, breaks, and lunch tracking
- **Admin Dashboard**: User management (CRUD operations)
- **Analytics**: Track total hours per employee with date filters
- **Responsive Design**: Clean UI using Tailwind CSS

## Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd time-tracker
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install Node dependencies:
```bash
npm install
```

4. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```

5. Update the `.env` file with your database configuration:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=time_tracker
DB_USERNAME=root
DB_PASSWORD=
```

6. Run migrations and seeders:
```bash
php artisan migrate --seed
```

This will create:
- An admin user: `admin@example.com` / `password`
- An employee user: `employee@example.com` / `password`

7. Build the frontend:
```bash
npm run build
```

8. Start the development server:
```bash
php artisan serve
```

9. In a separate terminal, watch for frontend changes:
```bash
npm run dev
```

## Default Credentials

- **Admin**: admin@example.com / password
- **Employee**: employee@example.com / password

## API Routes

All time tracking operations use API routes:

### Time Entry Routes (Authenticated)
- `GET /api/time-entries/current` - Get current day's entry
- `POST /api/time-entries/clock-in` - Clock in
- `POST /api/time-entries/clock-out` - Clock out
- `POST /api/time-entries/break-start` - Start break
- `POST /api/time-entries/break-end` - End break
- `POST /api/time-entries/lunch-start` - Start lunch
- `POST /api/time-entries/lunch-end` - End lunch
- `GET /api/time-entries/my-entries` - Get my time entries

### Admin Routes (Admin Only)
- `GET /api/admin/users` - Get all users
- `POST /api/admin/users` - Create user
- `GET /api/admin/users/{id}` - Get user details
- `PUT /api/admin/users/{id}` - Update user
- `DELETE /api/admin/users/{id}` - Delete user
- `GET /api/admin/analytics` - Get analytics data
- `GET /api/admin/analytics/user/{user}` - Get user analytics

## Database Schema

### Users Table
- `id` - Primary key
- `name` - User's name
- `email` - User's email
- `password` - Hashed password
- `role` - admin, manager, or employee
- `created_at` - Timestamp
- `updated_at` - Timestamp

### Time Entries Table
- `id` - Primary key
- `user_id` - Foreign key to users
- `date` - Entry date
- `clock_in` - Clock in time
- `clock_out` - Clock out time
- `break_start` - Break start time
- `break_end` - Break end time
- `lunch_start` - Lunch start time
- `lunch_end` - Lunch end time
- `total_hours` - Calculated total hours
- `created_at` - Timestamp
- `updated_at` - Timestamp

## User Roles

### Admin
- Full access to all features
- Can manage users (CRUD)
- Can view analytics
- Can view all time entries

### Manager
- Can view analytics
- Can view users list

### Employee
- Can track time (clock in/out, breaks, lunch)
- Can view their own time entries
- Limited access to other features

## Technologies Used

- **Backend**: Laravel 11
- **Frontend**: Vue.js 3 with Vite
- **CSS Framework**: Tailwind CSS
- **Authentication**: Laravel Breeze
- **ORM**: Eloquent
- **Testing**: PHPUnit

## Development

Run tests:
```bash
php artisan test
```

Code style:
```bash
composer pint
npm run lint
```

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
