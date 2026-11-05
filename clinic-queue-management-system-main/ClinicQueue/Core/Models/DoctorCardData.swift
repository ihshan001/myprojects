//
//  DoctorCardData.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-02-10.
//

import SwiftUI

struct DoctorCardData: Identifiable {
    let id = UUID()
    let icon: String
    let iconSize: CGFloat
    let title: String
    let subTitle: String
    let description1: String
    let description2: String
    let label1: String
    let label1Text: String
    let label2: String
    let label2Text: String
    let buttonText: String
    let cardBackground: Color = AppColors.cardBackground
}
