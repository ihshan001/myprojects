# [EASPATAAL - Patient Portal](https://github.com/gaureshpai/easpataal/tree/main/patient)

## Project Description

This is the patient-facing application for EASPATAAL, designed to enhance the patient experience by providing real-time information and engagement tools. It allows patients to track their queue status, view token details, manage their profiles, and submit feedback directly from their devices.

## Features

-   **Real-Time Queue Tracking:** Patients can view their current position in the queue and estimated wait times.
-   **Token Information:** Displays detailed token information, including assigned counter and doctor.
-   **Profile Management:** Allows patients to view and update their personal information.
-   **Feedback System:** Provides a channel for patients to submit feedback on their hospital experience.
-   **Progressive Web App (PWA):** Offers an app-like experience, including offline capabilities and installability.

## Tech Stack

This project is a modern web application built with the following key technologies:

- **Frontend:**
  - [Next.js](https://nextjs.org/) (v15) - React framework for building fast and scalable web applications.
  - [React](https://reactjs.org/) (v19) - A JavaScript library for building user interfaces.
  - [Tailwind CSS](https://tailwindcss.com/) - A utility-first CSS framework for rapid UI development.
  - [Radix UI](https://www.radix-ui.com/) - High-quality, accessible UI components.
  - [Recharts](http://recharts.org/en-US/) - Composed charting library built with React and D3.
- **Backend/Database:**
  - [Prisma](https://www.prisma.io/) (v6) - Next-generation ORM for Node.js and TypeScript, used for database access.
- **Messaging:**
  - [Twilio](https://www.twilio.com/) - For integrating SMS or other communication functionalities.
- **Progressive Web App:**
  - [Next-PWA](https://github.com/shadowwalker/next-pwa) - Plugin to easily enable PWA features in Next.js projects.

## Scripts Overview

-   `dev`: Starts the Next.js development server.
-   `build`: Builds the Next.js application for production.
-   `start`: Starts the Next.js production server.
-   `postinstall`: Automatically runs `prisma generate` after `npm install`.
-   `build:ci`: Provides a build status for continuous integration environments.

## Getting Started

To get a local copy up and running, follow these simple steps.

### Prerequisites

- Node.js (v18 or later)
- npm or yarn

### Installation

1.  Clone the repository:
    ```sh
    git clone https://github.com/gaureshpai/easpataal.git
    cd easpataal/patient
    ```
2.  Install NPM packages:
    ```sh
    npm install
    ```
3.  Set up your environment variables:
    Create a `.env` file in the root of the `patient` directory (if not already present) and populate it with your database connection string, Twilio credentials, and any other necessary environment variables. Refer to `.env.example` if available.

### Database Setup

-   Ensure your database is running and accessible. The `postinstall` script will automatically generate the Prisma client.
    If not automatically run, you can manually generate the Prisma client and push the schema:
    ```sh
    npm run postinstall
    # or
    npx prisma generate
    npx prisma db push
    ```

### Running the Application

-   To run the development server:
    ```sh
    npm run dev
    ```
    Open [http://localhost:3000](http://localhost:3000) in your browser.
