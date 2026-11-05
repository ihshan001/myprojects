//
//  ProfileView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-13.
//

import SwiftUI
struct SocialIconButton: View {
    let iconName: String
    
    var body: some View {
        Button(action: {
        }) {
            Image(iconName)
                .resizable()
                .scaledToFit()
                .frame(width: 40, height: 40)
        }
    }
}
struct MyProfileView: View {
    @State private var firstName: String = ""
    @State private var lastName: String = ""
    @State private var gender: String? = nil
    @State private var email: String = "johnDo26@gmail.com"
    @State private var contactNumber: String = ""
    @State private var dob: Date = Date()
    
    private let genderOptions: [DropdownOption] = [
        DropdownOption(key: "male", label: "Male"),
        DropdownOption(key: "female", label: "Female"),
        DropdownOption(key: "other", label: "Other")
    ]
    
    var body: some View {
        VStack(spacing: 0) {
            
            ScrollView {
                VStack(spacing: 25) {
                    
                    HStack {
                        Spacer()
                        Text("My Profile")
                            .font(.system(size: 22, weight: .bold))
                        Spacer()
                        Image(systemName: "chevron.left").opacity(0)
                    }
                    .padding(.horizontal)
                    
                    ZStack(alignment: .bottomTrailing) {
                        Image("profile")
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                            .frame(width: 120, height: 120)
                            .clipShape(Circle())
                        
                        Button(action: { /* Upload Image */ }) {
                            Image(systemName: "arrow.up.circle.fill")
                                .resizable()
                                .frame(width: 32, height: 32)
                                .foregroundColor(.red)
                                .background(Color.white.clipShape(Circle()))
                        }
                        .offset(x: -13, y: -8)
                    }
                    .padding(.vertical, 10)
                    
                    VStack(spacing: 16) {
                        InputField(placeholder: "First Name", value: $firstName)
                        InputField(placeholder: "Last Name", value: $lastName)
                        
                        CustomDropdown(
                            placeholder: "Gender",
                            options: genderOptions,
                            selectedKey: $gender
                        )
                        
                        InputField(placeholder: "Email", value: $email)
                            .disabled(true)
                        
                        InputField(placeholder: "Contact Number", value: $contactNumber)
                        
                        DatePickerField(placeholder: "Date of Birth", selection: $dob)
                    }
                    .padding(.horizontal)
                    
                    VStack(spacing: 12) {
                        Text("Link your profile")
                            .font(.system(size: 16, weight: .bold))
                        
                        Text("Linking your profile helps you receive important clinic updates, bills, and access your records across devices.")
                            .font(.system(size: 12))
                            .foregroundColor(.gray)
                            .multilineTextAlignment(.center)
                            .padding(.horizontal, 30)
                        
                        HStack(spacing: 20) {
                            SocialIconButton(iconName: "apple")
                            SocialIconButton(iconName: "gmail")
                        }
                        .padding(.top, 10)
                    }
                    .padding(.bottom, 100) // extra padding so ScrollView content doesn't get hidden by button
                }
                .padding(.top, 20)
            }
            
            // Fixed Save Button at bottom
            VStack {
                PrimaryButton(title: "Save", maxWidth: .infinity) {
                    print("Profile Saved")
                }
            }
            .padding(20)
            .background(Color(UIColor.systemBackground))
            .shadow(color: Color.black.opacity(0.05), radius: 5, x: 0, y: -5)
        }
        .edgesIgnoringSafeArea(.bottom)
    }
}
