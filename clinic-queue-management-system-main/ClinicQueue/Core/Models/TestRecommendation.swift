//
//  TestRecommendation.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-10.
//

import Foundation

struct TestRecommendation {
    
    
    static func recommendedTests(forDoctorSpecialty specialty: String) -> [ClinicStep] {
          var recommended: [ClinicStep] = []

          switch specialty.lowercased() {
              
          case "pediatrician", "pediatric specialist":
              if let cbc = LabData.labTest.first(where: { $0.name.contains("CBC") }) {
                  recommended.append(cbc)
              }
              if let urinalysis = LabData.labTest.first(where: { $0.name.contains("Urinalysis") }) {
                  recommended.append(urinalysis)
              }
              
          case "cardiologist":
              if let lipid = LabData.labTest.first(where: { $0.name.contains("Lipid") }) {
                  recommended.append(lipid)
              }
              if let ctCoronary = ImagingData.imagingTests.first(where: { $0.name.contains("CT Coronary") }) {
                  recommended.append(ctCoronary)
              }

          case "neurologist", "neurosurgeon":
              if let mriBrain = ImagingData.imagingTests.first(where: { $0.name.contains("MRI Brain") }) {
                  recommended.append(mriBrain)
              }

          case "orthopedic surgeon":
              if let xrayKnee = ImagingData.imagingTests.first(where: { $0.name.contains("X-Ray - Knee") }) {
                  recommended.append(xrayKnee)
              }
              if let dxa = ImagingData.imagingTests.first(where: { $0.name.contains("DXA Bone Density") }) {
                  recommended.append(dxa)
              }

          case "gastroenterologist":
              if let lft = LabData.labTest.first(where: { $0.name.contains("Liver") }) {
                  recommended.append(lft)
              }
              if let abdUltrasound = ImagingData.imagingTests.first(where: { $0.name.contains("Abdomen") }) {
                  recommended.append(abdUltrasound)
              }

          case "urologist", "kidney specialist":
              if let kft = LabData.labTest.first(where: { $0.name.contains("Kidney") }) {
                  recommended.append(kft)
              }
              if let urinalysis = LabData.labTest.first(where: { $0.name.contains("Urinalysis") }) {
                  recommended.append(urinalysis)
              }

          case "dermatologist", "cosmetic dermatologist":

              break

          case "obstetrician":
              if let mammogram = ImagingData.imagingTests.first(where: { $0.name.contains("Mammography") }) {
                  recommended.append(mammogram)
              }

          default:
              break
          }

          return recommended
      }
    
    
    static func recommendedTests(for symptomKey: String) -> [ClinicStep] {
        var recommended: [ClinicStep] = []

        switch symptomKey.lowercased() {
        case "eye":
            if let cbc = LabData.labTest.first(where: { $0.name.contains("CBC") }) {
                recommended.append(cbc)
            }
            if let mri = ImagingData.imagingTests.first(where: { $0.name.contains("MRI Brain") }) {
                recommended.append(mri)
            }
            
        case "chest":
            if let lipid = LabData.labTest.first(where: { $0.name.contains("Lipid") }) {
                recommended.append(lipid)
            }
            if let xray = ImagingData.imagingTests.first(where: { $0.name.contains("Chest X-Ray") }) {
                recommended.append(xray)
            }
            
        case "fever":
            if let cbc = LabData.labTest.first(where: { $0.name.contains("CBC") }) {
                recommended.append(cbc)
            }
            
        case "stomach":
            if let lft = LabData.labTest.first(where: { $0.name.contains("Liver") }) {
                recommended.append(lft)
            }
            if let abdUltrasound = ImagingData.imagingTests.first(where: { $0.name.contains("Abdomen") }) {
                recommended.append(abdUltrasound)
            }
            
        default:
            break
        }

        return recommended
    }
}
