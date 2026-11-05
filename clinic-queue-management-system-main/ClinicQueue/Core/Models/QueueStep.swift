//
//  QueueStep.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

enum QueueStep {
    case waiting, ready, inProgress, completed
}

struct LabTest: Identifiable {
    var id = UUID()
    var name: String
    var isCompleted: Bool = false
}

struct ImagingTest: Identifiable {
    var id = UUID()
    var name: String
    var isCompleted: Bool = false
}

struct PatientSession: Identifiable {
    var id = UUID()
    var name: String
    var age: Int
    var gender: String
    var symptoms: [String] = []
    var selectedSymptom: Symptom?

    var assignedDoctor: InfoCardData?
    var clinicSteps: [ClinicStep] = []    
    var pharmacyCart: [String] = []
    var appointmentDate: Date?

    var isSessionComplete: Bool {
        clinicSteps.allSatisfy { $0.isFinished }
    }

    var currentStep: ClinicStep? {
        clinicSteps.first { !$0.isFinished && !$0.isCancelled }
    }
}
