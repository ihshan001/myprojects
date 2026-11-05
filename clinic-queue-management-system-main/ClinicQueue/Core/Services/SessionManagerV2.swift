//
//  SessionManagerV2.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-11.
//

import SwiftUI
import Combine

// Tracks whether an appointment was refunded so ChannelingHistoryView can display a refund card
struct UpcomingAppointment: Identifiable {
    let id: UUID
    let date: Date
    let patientName: String
    let age: Int
    let gender: String
    let activities: [Activity]
    let totalFee: Double
    var isRefunded: Bool

    init(
        id: UUID,
        date: Date,
        patientName: String,
        age: Int,
        gender: String,
        activities: [Activity],
        totalFee: Double,
        isRefunded: Bool = false
    ) {
        self.id = id
        self.date = date
        self.patientName = patientName
        self.age = age
        self.gender = gender
        self.activities = activities
        self.totalFee = totalFee
        self.isRefunded = isRefunded
    }
}

class SessionManagerV2: ObservableObject {

    @Published var currentService: ServiceType = .unknown
    @Published var activities: [Activity] = []
    @Published var symptoms: [Symptom] = []
    @Published var scheduledLab: [Activity] = []
    @Published var scheduledTest: [Activity] = []
    @Published var upcomingAppointments: [UpcomingAppointment] = []
    @Published var currentClinicVisit: ClinicVisit? = nil

 
    @Published var hasRejoinedDoctor: Bool = false


    @Published var currentAppointmentId: UUID? = nil

    private var cancellables = Set<AnyCancellable>()

    init() {
        $currentService
            .sink { newValue in
                print("currentService changed to:", newValue.rawValue)
            }
            .store(in: &cancellables)
    }

    func addActivity(service: ServiceType) {
        let activity = Activity(service: service)
        activities.append(activity)
        currentService = service
    }

    func updateQueue(activityId: UUID, number: Int, stage: QueueStages = .wait) {
        guard let index = activities.firstIndex(where: { $0.id == activityId }) else { return }
        activities[index].queueNumber = number
        activities[index].queueStage = stage
        activities[index].stage = .inQueue
    }

    func updateDoctor(activityId: UUID, doctor: DoctorCategoryGroup) {
        guard let index = activities.firstIndex(where: { $0.id == activityId }) else { return }
        activities[index].doctor = doctor
        activities[index].stage = .planning
    }

    func completeActivity(activityId: UUID) {
        guard let index = activities.firstIndex(where: { $0.id == activityId }) else { return }
        activities[index].stage = .completed
        activities[index].queueStage = .completed
    }

    func cancelActivity(activityId: UUID) {
        guard let index = activities.firstIndex(where: { $0.id == activityId }) else { return }
        activities[index].stage = .canceled
        activities[index].queueStage = .cancel
    }

    func currentActivity() -> Activity? {
        return activities.last
    }

    func saveUpcomingAppointment(patientName: String, age: Int, gender: String, activities: [Activity]) {
        let totalActivityFee = activities.reduce(0.0) { partialResult, activity in
            let fee = Double(activity.selectedDoctor?.price?.replacingOccurrences(of: "$", with: "") ?? "0") ?? 0
            return partialResult + fee
        }

        let total = totalActivityFee + PaymentConfig.adminFee

        let appointment = UpcomingAppointment(
            id: UUID(),
            date: Date(),
            patientName: patientName,
            age: age,
            gender: gender,
            activities: activities,
            totalFee: total
        )

        upcomingAppointments.append(appointment)
    }

    func resetFlow() {
        currentService = .unknown
        activities.removeAll()
        symptoms.removeAll()
        hasRejoinedDoctor = false
        currentAppointmentId = nil
    }

    func selectActivity(activityId: UUID) {
        for index in activities.indices {
            activities[index].isSelected = activities[index].id == activityId
        }
    }

    func activity(for service: ServiceType) -> Activity? {
        return activities.first(where: { $0.service == service })
    }


    func refundCurrentAppointment() {
        guard let apptId = currentAppointmentId else { return }
        if let index = upcomingAppointments.firstIndex(where: { $0.id == apptId }) {
            upcomingAppointments[index].isRefunded = true
        }
    }

    func printAllActivities() {
        for activity in activities {
            print("Activity \(activity.id)")
            print(" service: \(activity.service)")
            print(" stage: \(activity.stage)")
            print(" queueStage: \(activity.queueStage)")
            print(" selected: \(activity.isSelected)")
            print(" patientName: \(activity.patientName ?? "")")
            print(" patientAge: \(activity.patientAge ?? 0)")
            print(" patientGender: \(activity.patientGender ?? "")")
            print(" symptoms: \(activity.symptoms)")
            if let doc = activity.selectedDoctor {
                print(" doctor: \(doc.heading) - \(doc.subheading)")
            } else {
                print(" doctor: nil")
            }
        }
    }
}


enum ServiceType: String, CaseIterable {
    case clinic
    case lab
    case imaging
    case pharmacy
    case appointment
    case unknown
}

enum ActivityStage: String {
    case unknown
    case planning
    case inQueue
    case completed
    case canceled
    case refund
}

enum QueueStages: String {
    case unknown
    case wait
    case next
    case ready
    case inProgress
    case completed
    case cancel
}

struct Activity: Identifiable {
    var id = UUID()
    var service: ServiceType
    var stage: ActivityStage = .unknown
    var doctor: DoctorCategoryGroup?
    var selectedDoctor: InfoCardData?
    var queueNumber: Int?
    var isSelected: Bool = false
    var queueStage: QueueStages = .unknown
    var labStep: ClinicStep?
    var imagingStep: ClinicStep?
    var pharmacyStep: ClinicStep?
    var appointmentDate: Date?
    var patientName: String?
    var patientAge: Int?
    var patientGender: String?
    var symptoms: [String] = []
    var testName: String?
}
