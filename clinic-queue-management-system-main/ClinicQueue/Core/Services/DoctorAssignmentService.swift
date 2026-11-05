//
//  DoctorAssignmentService.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import Foundation

struct DoctorAssignmentService {


    static func findSpecialty(from symptoms: [String]) -> String {

        let matches = SymptomData.symptoms.filter {
            symptoms.contains($0.key)
        }

        let specialties = matches.map { $0.specialty }

        return specialties.first ?? "General"
    }



    static func doctors(for specialty: String) -> [InfoCardData] {

        guard let group = DoctorData.doctorGroups.first(where: {
            $0.specialty == specialty
        }) else { return [] }

        return group.doctors
    }



    static func leastBusyDoctor(from doctors: [InfoCardData]) -> InfoCardData? {

        return doctors.min {
            extractQueue($0.activeQueueCount ?? "0") < extractQueue($1.activeQueueCount ?? "0")
        }

    }



    private static func extractQueue(_ text: String) -> Int {

        let number = text.components(separatedBy: " ").first ?? "0"
        return Int(number) ?? 0
    }


    static func assignDoctor(symptoms: [String]) -> InfoCardData? {

        let specialty = findSpecialty(from: symptoms)

        let doctors = doctors(for: specialty)

        return leastBusyDoctor(from: doctors)
    }

}
