//
//  ClinicVisit.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import Foundation

struct ClinicVisit: Identifiable {

    let id = UUID()


    var patientName: String
    var age: Int
    var gender: String


    var symptomStrings: [String] = []


    var selectedSymptom: Symptom?



    var doctorName: String?
    var specialty: String?


    var queueNumber: String?
    var estimatedWait: String?


    var paymentMethod: String?
    var consultationFee: Double?
    var adminFee: Double?
    var totalPayment: Double {

        (consultationFee ?? 0.0) + (adminFee ?? 0.0) - PaymentConfig.additionalDiscount
    }
    
    
    var steps: [ClinicStep] = []
    
    mutating func updateStep(_ step: ClinicStep) {
        if let index = steps.firstIndex(where: { $0.id == step.id }) {
            steps[index] = step
        } else {
            steps.append(step)
        }
    }
    
    var doctorStep: ClinicStep? {
          get { steps.first(where: { $0.type == .doctor }) }
          set {
              guard let newStep = newValue else { return }
              updateStep(newStep)
          }
      }
    
    var currentStepIndex: Int = 0
     var currentStep: ClinicStep? {
         guard steps.indices.contains(currentStepIndex) else { return nil }
         return steps[currentStepIndex]
     }
    
    var status: StepStatus {
           steps.last?.status ?? .pending
       }
    
}

extension ClinicVisit {
    var isSessionComplete: Bool {
        steps.allSatisfy { $0.isFinished }
    }
}
