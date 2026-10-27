# [EASPATAAL - Hospital Queue Management System (Main Application)](https://github.com/gaureshpai/easpataal/tree/main/main)

## Project Description

This is the main application for the EASPATAAL Hospital Queue Management System, designed for hospital staff. It offers comprehensive dashboards for various roles including administrators, doctors, receptionists, and pharmacists, to efficiently manage patient flow, appointments, and services within the hospital.

## Features

-   **Admin Dashboard:** Comprehensive tools for managing users, counters, departments, and viewing system-wide statistics.
-   **Doctor Dashboard:** Streamlined interface for managing patient queues, viewing patient details, and creating prescriptions.
-   **Receptionist Dashboard:** Functionality to register new patients, generate tokens, and manage patient information efficiently.
-   **Pharmacist Dashboard:** Tools to view and fulfill prescription orders, and manage drug inventory.
-   **Real-Time Updates:** Utilizes web push notifications for critical updates.
-   **Progressive Web App (PWA):** Offers an app-like experience, including offline capabilities and installability.

## Tech Stack

This project is a robust, modern web application built with the following key technologies:

- **Frontend:**
  - [Next.js](https://nextjs.org/) (v15) - React framework for building fast and scalable web applications.
  - [React](https://reactjs.org/) (v19) - A JavaScript library for building user interfaces.
  - [Tailwind CSS](https://tailwindcss.com/) - A utility-first CSS framework for rapid UI development.
  - [Radix UI](https://www.radix-ui.com/) - High-quality, accessible UI components.
  - [Recharts](http://recharts.org/en-US/) - Composed charting library built with React and D3 for data visualization.
- **Backend/Database:**
  - [Prisma](https://www.prisma.io/) (v6) - Next-generation ORM for Node.js and TypeScript, used for database access.
- **Authentication:**
  - [NextAuth.js](https://next-auth.js.org/) (v4) - Flexible authentication for Next.js applications.
- **Messaging & Notifications:**
  - [Twilio](https://www.twilio.com/) - For integrating SMS or other communication functionalities.
  - [Web-Push](https://www.npmjs.com/package/web-push) - For sending push notifications to web browsers.
- **Progressive Web App:**
  - [Next-PWA](https://github.com/shadowwalker/next-pwa) - Plugin to easily enable PWA features in Next.js projects.

## Scripts Overview

-   `dev`: Starts the Next.js development server.
-   `build`: Builds the Next.js application for production.
-   `start`: Starts the Next.js production server.
-   `postinstall`: Automatically runs `prisma generate` after `npm install`.
-   `build:ci`: Provides a build status for continuous integration environments.
-   `seed`: Runs database seeding scripts (using `ts-node`).
-   `migrate`: Runs Prisma database migrations.
-   `test`: Runs Jest tests.

## Getting Started

To get a local copy up and running, follow these simple steps.

### Prerequisites

- Node.js (v18 or later)
- npm or yarn
- Database (e.g., PostgreSQL, MySQL compatible with Prisma)

### Installation

1.  Clone the repository:
    ```sh
    git clone https://github.com/gaureshpai/easpataal.git
    cd easpataal/main
    ```
2.  Install NPM packages:
    ```sh
    npm install
    ```
3.  Set up your environment variables:
    Create a `.env` file in the root of the `main` directory (if not already present) and populate it with your database connection string, NextAuth secret, Twilio credentials, and VAPID keys for web push notifications. Refer to `.env.example` if available.

### Database Setup

-   Ensure your database is running and accessible.
-   Run Prisma migrations to set up the database schema:
    ```sh
    npm run migrate
    ```
-   Generate the Prisma client:
    ```sh
    npm run postinstall # or npx prisma generate
    ```
-   (Optional) Seed the database with initial data:
    ```sh
    npm run seed
    ```

### Running the Application

-   To run the development server:
    ```sh
    npm run dev
    ```
    Open [http://localhost:3000](http://localhost:3000) in your browser.
