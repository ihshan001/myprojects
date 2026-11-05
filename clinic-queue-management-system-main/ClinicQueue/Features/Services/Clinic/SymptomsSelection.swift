//
//  SymptomsSelection.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

struct SymptomsSelection: View {
    
    @EnvironmentObject var sessionManager: SessionManager
    @EnvironmentObject var session: SessionManagerV2

    private let symptoms: [CheckboxItem] = SymptomData.symptoms.map {
        CheckboxItem(key: $0.key, label: $0.label)
    }

    @State private var selectedSymptoms: Set<String> = []
    @State private var navigateToAppitmentStarter = false

    var body: some View {
        VStack(spacing: 0) {
            ScrollView(showsIndicators: false) {
                VStack(spacing: 24) {
 
                    VStack(spacing: 12) {
                        Text("Select Your Symptoms")
                            .font(.system(size: 20, weight: .bold))

                        Text("Choose the symptoms you’re experiencing. We’ll assign the most suitable available doctor and place you in the queue.")
                            .font(.system(size: 14))
                            .multilineTextAlignment(.center)
                            .foregroundColor(.secondary)
                    }
                    .padding(.top, 10)

                   
                    RoundCheckboxGroup(
                        items: symptoms,
                        selectedKeys: $selectedSymptoms
                    )
                }
                .padding(20)
            }


            VStack {
                PrimaryButton(title: "Proceed to Queue",  backgroundColor: !selectedSymptoms.isEmpty ? AppColors.primary : AppColors.primary.opacity(0.5)) {
                    let selectedKeysArray = Array(selectedSymptoms)
                    
                    if let firstSymptomKey = selectedKeysArray.first,
                       let symptomObj = SymptomData.symptoms.first(where: { $0.key == firstSymptomKey }) {
                        sessionManager.currentClinicVisit?.selectedSymptom = symptomObj
                    }
                    
                
                    if session.activity(for: .clinic) == nil {
                        session.addActivity(service: .clinic)
                    }
                    
                    sessionManager.currentClinicVisit?.symptomStrings = selectedKeysArray
                    
                    
                    if let activeActivityIndex = session.activities.firstIndex(where: { $0.isSelected }) {
                        var doctorActivity = session.activities[activeActivityIndex]
                        doctorActivity.symptoms = selectedKeysArray
                        
                        if let specialty = sessionManager.currentClinicVisit?.selectedSymptom?.specialty {
                            let doctors = DoctorData.doctorGroups
                                .first(where: { $0.specialty == specialty })?.doctors ?? []
                            
                            let selectedDoctor = DoctorData.leastBusyDoctor(from: doctors)
                            doctorActivity.selectedDoctor = selectedDoctor
                        }
                        
                       
                        doctorActivity.isSelected = true
                        
                      
                        session.activities[activeActivityIndex] = doctorActivity
                        session.printAllActivities()
                        
                      
                        var testActivities: [Activity] = []
                        for symptomKey in selectedKeysArray {
                            let tests = TestRecommendation.recommendedTests(for: symptomKey)
                            for test in tests {
                                var testActivity = Activity(
                                    id: UUID(),
                                    service: .clinic,
                                    stage: .unknown,
                                    doctor: nil,
                                    selectedDoctor: nil,
                                    queueNumber: nil,
                                    isSelected: false,
                                    queueStage: .unknown,
                                    labStep: test.type == .labTest ? test : nil,
                                    imagingStep: test.type == .imaging ? test : nil,
                                    pharmacyStep: nil,
                                    appointmentDate: nil,
                                    patientName: sessionManager.currentClinicVisit?.patientName ?? "",
                                    symptoms: [symptomKey],
                                    testName: test.name
                                )
                                testActivities.append(testActivity)
                            }
                        }
                        
                        session.activities.append(contentsOf: testActivities)
                        session.printAllActivities()
                    }
                    
                    navigateToAppitmentStarter = true
                }
                .disabled(selectedSymptoms.isEmpty)
            }
            .padding(20)
            .background(Color(UIColor.systemBackground))
            .shadow(color: Color.black.opacity(0.05), radius: 5, x: 0, y: -5) 
        }
        .navigationDestination(isPresented: $navigateToAppitmentStarter) {
            AppointmentStarterView().environmentObject(sessionManager)
        }
    }
}
#Preview {
    SymptomsSelection()
}
