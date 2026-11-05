//
//  StepManager.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import Foundation

struct StepManager {
    var steps: [ClinicStep]
    var currentIndex: Int = 0

    var currentStep: ClinicStep? {
        guard steps.indices.contains(currentIndex) else { return nil }
        return steps[currentIndex]
    }

    mutating func completeCurrentStep() {
        guard steps.indices.contains(currentIndex) else { return }
        steps[currentIndex].status = .completed
        if currentIndex + 1 < steps.count {
            currentIndex += 1
            steps[currentIndex].status = .waiting
        }
    }

    mutating func addStep(_ step: ClinicStep) {
        steps.append(step)
    }
}
