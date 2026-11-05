//
//  ClinicManager.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

//
//  ClinicManager.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI
import Combine

class ClinicManager: ObservableObject {
    @Published var sessions: [PatientSession] = []


    func startSession(name: String, age: Int, gender: String) -> UUID {
        let session = PatientSession(name: name, age: age, gender: gender)
        sessions.append(session)
        return session.id
    }


    func getSession(id: UUID) -> PatientSession? {
        sessions.first { $0.id == id }
    }


    func updateSession(_ session: PatientSession) {
        if let index = sessions.firstIndex(where: { $0.id == session.id }) {
            sessions[index] = session
        }
    }


    func advanceClinicQueue(for sessionId: UUID) {
        guard var session = getSession(id: sessionId),
              let index = session.clinicSteps.firstIndex(where: { !$0.isFinished && !$0.isCancelled }) else { return }

        var step = session.clinicSteps[index]

        switch step.status {
        case .pending:
            step.status = .waiting
        case .waiting:
            step.status = .inProgress
        case .next:
            step.status = .next
        case .ready:
            step.status = .ready
        case .inProgress:
            step.status = .completed
            step.isFinished = true

            
            if step.type == .doctor, let symptomKey = session.selectedSymptom?.key {
                let recommendedTests = TestRecommendation.recommendedTests(for: symptomKey)
                
                let labTests = recommendedTests.filter { $0.type == .labTest }
                let imagingTests = recommendedTests.filter { $0.type == .imaging }

                print("Adding Lab Tests to session \(session.id): \(labTests.map { $0.name })")
                print("Adding Imaging Tests to session \(session.id): \(imagingTests.map { $0.name })")
                
                for lab in labTests {
                    addLabTest(for: session.id, testName: lab.name, estimatedWait: lab.estimatedWait)
                    if let stepId = session.clinicSteps.last?.id {
                        autoAdvanceStep(for: session.id, stepId: stepId, after: 5)
                    }
                }

                for imaging in imagingTests {
                    addImagingTest(for: session.id, testName: imaging.name, estimatedWait: imaging.estimatedWait)
                    if let stepId = session.clinicSteps.last?.id {
                        autoAdvanceStep(for: session.id, stepId: stepId, after: 10) 
                    }
                }
            }

        case .completed:
            break
        }

        session.clinicSteps[index] = step
        updateSession(session)
    }
    
    


    func addLabTest(for sessionId: UUID, testName: String, estimatedWait: String? = nil) {
        guard var session = getSession(id: sessionId) else { return }

        let labStep = ClinicStep(
            type: .labTest,
            name: testName,
            estimatedWait: estimatedWait,
            serviceImage: Image("LabIcon")
        )

        session.clinicSteps.append(labStep)
        updateSession(session)
    }
    
    func addImagingTest(for sessionId: UUID, testName: String, estimatedWait: String? = nil) {
        guard var session = getSession(id: sessionId) else { return }

        let imagingStep = ClinicStep(
            type: .imaging,
            name: testName,
            estimatedWait: estimatedWait,
            serviceImage: Image("ImagingIcon")
        )

        session.clinicSteps.append(imagingStep)
        updateSession(session)
    }

    func completeLabTest(for sessionId: UUID, testId: UUID) {
        guard var session = getSession(id: sessionId),
              let index = session.clinicSteps.firstIndex(where: { $0.id == testId && $0.type == .labTest }) else { return }

        session.clinicSteps[index].isFinished = true
        session.clinicSteps[index].status = .completed
        updateSession(session)
    }


    func finishSession(for sessionId: UUID) {
        guard var session = getSession(id: sessionId) else { return }

        for index in session.clinicSteps.indices {
            session.clinicSteps[index].isFinished = true
        }

        updateSession(session)
    }
    
    
    func autoAdvanceStep(for sessionId: UUID, stepId: UUID, after seconds: Double) {
        DispatchQueue.main.asyncAfter(deadline: .now() + seconds) { [weak self] in
            guard let self = self, var session = self.getSession(id: sessionId),
                  let index = session.clinicSteps.firstIndex(where: { $0.id == stepId }) else { return }

            var step = session.clinicSteps[index]
            guard !step.isFinished else { return }

            step.status = .completed
            step.isFinished = true
            session.clinicSteps[index] = step
            self.updateSession(session)

            print("Auto-completed step: \(step.name) for session \(sessionId)")
        }
    }
}

