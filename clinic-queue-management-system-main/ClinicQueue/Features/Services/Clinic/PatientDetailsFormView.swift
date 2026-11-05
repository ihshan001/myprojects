//
//  PatientDetailsFormView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

extension PatientDetailsFormView {
    


    var isFormValid: Bool {
        isNameValid && isAgeValid && gender != nil
    }

    var isNameValid: Bool {
        if name.trimmingCharacters(in: .whitespaces).isEmpty {
            return false
        }

        let hasNumber = name.rangeOfCharacter(from: .decimalDigits) != nil
        return !hasNumber
    }

    var isAgeValid: Bool {
        guard let ageInt = Int(age) else { return false }
        return ageInt > 0 && ageInt < 150
    }

}




struct PatientDetailsFormView: View {
    @EnvironmentObject var sessionManager: SessionManager
    @EnvironmentObject var session: SessionManagerV2
    @State private var name: String = ""
    @State private var age: String = ""
    @State private var gender: String? = nil
    @State private var navigateToSymptoms = false

    private let genderOptions: [DropdownOption] = [
        DropdownOption(key: "male", label: "Male"),
        DropdownOption(key: "female", label: "Female"),
        DropdownOption(key: "other", label: "Other")
    ]

    var body: some View {

        NavigationStack {

            VStack(alignment: .leading, spacing: 20) {

                VStack(alignment: .leading, spacing: 8) {

                    Text("Confirm Your Details")
                        .font(.system(size: 20, weight: .bold))

                    Text("Please verify your information before joining the consultation queue.")
                        .font(.system(size: 14))
                        .foregroundColor(.secondary)
                }

                VStack(spacing: 16) {

                    InputField(
                        placeholder: "Patient Name",
                        value: $name
                    )

                    InputField(
                        placeholder: "Age",
                        value: $age
                    )

                    CustomDropdown(
                     placeholder: "Gender",
                     options: genderOptions,
                     selectedKey: $gender,
                    
                     )
                }

                Spacer()

                PrimaryButton(title: "Proceed to Queue") {

                   
                    session.activities.removeAll { $0.service == .clinic }
                    session.hasRejoinedDoctor = false

                    sessionManager.currentClinicVisit = ClinicVisit(
                        patientName: name,
                        age: Int(age) ?? 0,
                        gender: gender ?? "",
                        symptomStrings: []
                    )

                    session.currentService = .clinic
                    session.addActivity(service: .clinic)

                    if let activity = session.activity(for: .clinic) {
                        if let index = session.activities.firstIndex(where: { $0.id == activity.id }) {
                            session.activities[index].patientName = name
                            session.activities[index].patientAge = Int(age) ?? 0
                            session.activities[index].patientGender = gender ?? ""
                            session.activities[index].isSelected = true
                            session.activities = session.activities
                            session.printAllActivities()
                        }
                    }

                    navigateToSymptoms = true

                }
                .disabled(!isFormValid)
                .opacity(isFormValid ? 1 : 0.5)

            }
            .padding(.horizontal)
            .padding(.top, 20)

            .navigationDestination(isPresented: $navigateToSymptoms) {
                SymptomsSelection().environmentObject(sessionManager)
            }
            
            FloatingNav(
                mainIcon: "plus",
                items: [
                    FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                    FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                    FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(SettingsView()))
                ]
            )

        }
        
        
    }
    
    
}


#Preview {
    PatientDetailsFormView()
}
