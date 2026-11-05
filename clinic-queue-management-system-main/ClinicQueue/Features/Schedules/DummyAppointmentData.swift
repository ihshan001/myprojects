//
//  DummyAppointmentData.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-12.
//

import SwiftUI

struct DummyAppointments {

    static func generate() -> [UpcomingAppointment] {
        var appointments: [UpcomingAppointment] = []

        let allDoctors = DoctorData.doctorGroups.flatMap { $0.doctors }

        print("===== DUMMY APPOINTMENT GENERATION START =====")


      
        if let todayDoctor = allDoctors.first {

            let labTests = LabData.labTests.prefix(2)
            let imagingTests = ImagingData.imagingTests.prefix(1)

            var activities: [Activity] = []

            activities.append(
                Activity(
                    service: .appointment,
                    stage: .planning,
                    selectedDoctor: todayDoctor,
                    isSelected: true,
                    queueStage: .wait
                )
            )

            print("Added Doctor Activity:", todayDoctor.heading ?? "Doctor")

            for test in labTests {
                let labStep = LabData.labTest.first(where: { $0.name == test.title })
                activities.append(
                    Activity(
                        service: .appointment,
                        stage: .planning,
                        selectedDoctor: nil,
                        isSelected: false,
                        queueStage: .unknown,
                        labStep: labStep,
                        testName: test.title
                    )
                )
                print("Added Lab Activity:", test.title)
            }

            for test in imagingTests {
                let imagingStep = ImagingData.imagingTests.first(where: { $0.name == test.name })
                activities.append(
                    Activity(
                        service: .appointment,
                        stage: .planning,
                        selectedDoctor: nil,
                        isSelected: false,
                        queueStage: .unknown,
                        imagingStep: imagingStep,
                        testName: test.name
                    )
                )
                print("Added Imaging Activity:", test.name)
            }

            appointments.append(UpcomingAppointment(
                id: UUID(),
                date: Date(),
                patientName: "John Doe",
                age: 32,
                gender: "Male",
                activities: activities,
                totalFee: 85.00
            ))
            print("Today's appointment created with \(activities.count) activities")
        }


     
        let upcomingOffsets = [1, 2, 3]
        for (i, offset) in upcomingOffsets.enumerated() {
            if let doctor = allDoctors[safe: i + 1] {
                let activity = Activity(
                    service: .appointment,
                    stage: .planning,
                    selectedDoctor: doctor,
                    isSelected: false,
                    queueStage: .wait
                )
                appointments.append(UpcomingAppointment(
                    id: UUID(),
                    date: Calendar.current.date(byAdding: .day, value: offset, to: Date()) ?? Date(),
                    patientName: ["Sarah Lee", "Mike Chen", "Priya Raj"][i],
                    age: [28, 45, 33][i],
                    gender: ["Female", "Male", "Female"][i],
                    activities: [activity],
                    totalFee: [75.00, 90.00, 65.00][i]
                ))
                print("Upcoming appointment created for:", doctor.heading ?? "Doctor")
            }
        }


    
        let completedData: [(daysAgo: Int, name: String, age: Int, gender: String, fee: Double, tests: [String])] = [
            (3, "James Wilson", 52, "Male",   120.00, ["Complete Blood Count (CBC)", "Chest X-Ray (PA)"]),
            (7, "Emma Davis",  29, "Female",  95.00,  ["Urine Full Report", "Ultrasound Abdomen"]),
            (14, "Robert Kim", 41, "Male",    110.00, ["Lipid Panel", "Fasting Blood Sugar"]),
            (21, "Aisha Perera", 36, "Female", 80.00, ["ECG", "Full Blood Count"])
        ]
        for (i, data) in completedData.enumerated() {
            if let doctor = allDoctors[safe: i + 4] ?? allDoctors.last {
                var activities: [Activity] = [
                    Activity(
                        service: .appointment,
                        stage: .completed,
                        selectedDoctor: doctor,
                        isSelected: false,
                        queueStage: .completed
                    )
                ]
               
                for testName in data.tests.prefix(1) {
                    let labStep = LabData.labTest.first(where: { $0.name == testName })
                        ?? ClinicStep(type: .labTest, name: testName)
                    activities.append(Activity(
                        service: .appointment,
                        stage: .completed,
                        selectedDoctor: nil,
                        isSelected: false,
                        queueStage: .completed,
                        labStep: labStep,
                        testName: testName
                    ))
                }
                appointments.append(UpcomingAppointment(
                    id: UUID(),
                    date: Calendar.current.date(byAdding: .day, value: -data.daysAgo, to: Date()) ?? Date(),
                    patientName: data.name,
                    age: data.age,
                    gender: data.gender,
                    activities: activities,
                    totalFee: data.fee
                ))
                print("Completed appointment created for:", doctor.heading ?? "Doctor")
            }
        }


       
        if let refundDoctor = allDoctors[safe: 2] {
            appointments.append(UpcomingAppointment(
                id: UUID(),
                date: Calendar.current.date(byAdding: .day, value: -5, to: Date()) ?? Date(),
                patientName: "Nina Patel",
                age: 31,
                gender: "Female",
                activities: [Activity(
                    service: .appointment,
                    stage: .canceled,
                    selectedDoctor: refundDoctor,
                    isSelected: false,
                    queueStage: .cancel
                )],
                totalFee: 75.00,
                isRefunded: true
            ))
            print("Refunded (past) appointment created for:", refundDoctor.heading ?? "Doctor")
        }

        if let futureRefundDoctor = allDoctors[safe: 5] ?? allDoctors.last {
            appointments.append(UpcomingAppointment(
                id: UUID(),
                date: Calendar.current.date(byAdding: .day, value: 4, to: Date()) ?? Date(),
                patientName: "Daniel Tran",
                age: 44,
                gender: "Male",
                activities: [Activity(
                    service: .appointment,
                    stage: .canceled,
                    selectedDoctor: futureRefundDoctor,
                    isSelected: false,
                    queueStage: .cancel
                )],
                totalFee: 90.00,
                isRefunded: true
            ))
            print("Refunded (future) appointment created for:", futureRefundDoctor.heading ?? "Doctor")
        }


        print("===== FINAL APPOINTMENT STRUCTURE =====")
        for appointment in appointments {
           
            for (index, activity) in appointment.activities.enumerated() {
                print(
                    "  Activity \(index + 1):",
                    "Service:", activity.service,
                    "| TestName:", activity.testName ?? "Doctor",
                    "| Selected:", activity.isSelected,
                    "| QueueStage:", activity.queueStage,
                    "| Doctor:", activity.selectedDoctor?.heading ?? "None"
                )
            }
            print("-----------------------------")
        }
        print("===== DUMMY APPOINTMENT GENERATION END =====")

        return appointments
    }
}



extension Array {
    subscript(safe index: Int) -> Element? {
        return indices.contains(index) ? self[index] : nil
    }
}
